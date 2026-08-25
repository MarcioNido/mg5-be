#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
TEST_ROOT=$(mktemp -d)
trap 'rm -rf -- "$TEST_ROOT"' EXIT
REAL_PATH=$PATH

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }
assert_fails() { "$@" >/dev/null 2>&1 && fail "command unexpectedly succeeded: $*"; return 0; }
reset_log() { : >"$STUB_LOG"; }
assert_no_replacement() {
    if grep -q '^EVENT replace$' "$STUB_LOG"; then
        fail 'database replacement was attempted unexpectedly'
    fi
}
assert_no_partial_files() {
    local directory=$1
    if find "$directory" -type f -name '.mg5-backup-*' -print -quit | grep -q .; then
        fail 'partial backup was not cleaned up'
    fi
}

mkdir -p "$TEST_ROOT/bin" "$TEST_ROOT/backups"
cat >"$TEST_ROOT/bin/docker" <<'STUB'
#!/usr/bin/env bash
set -u
[[ "$1" == compose ]] || exit 90
shift
args=" $* "
if [[ -n "${STUB_LOG:-}" ]]; then
    printf '%s\n' "$args" >>"$STUB_LOG"
fi
if [[ "$args" == *'printf %s "$MYSQL_DATABASE"'* ]]; then
    printf '%s' "${STUB_DATABASE:-moneyguru5}"
elif [[ "$args" == *' mysqldump '* ]]; then
    printf 'EVENT dump\n' >>"${STUB_LOG:-/dev/null}"
    [[ "${STUB_DUMP_FAIL:-0}" == 0 ]] || exit 41
    printf '%s\n' '-- MySQL dump' 'CREATE TABLE `migrations` (`id` int);'
elif [[ "$args" == *'DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME'* ]]; then
    printf 'EVENT metadata\n' >>"${STUB_LOG:-/dev/null}"
    [[ "${STUB_METADATA_FAIL:-0}" == 0 ]] || exit 43
    printf '%s\t%s\n' "${STUB_CHARACTER_SET:-utf8mb4}" "${STUB_COLLATION:-utf8mb4_unicode_ci}"
elif [[ "$args" == *' DROP DATABASE '* && "$args" == *' CREATE DATABASE '* ]]; then
    printf 'EVENT replace\n' >>"${STUB_LOG:-/dev/null}"
    [[ "${STUB_RECREATE_FAIL:-0}" == 0 ]] || exit 44
elif [[ "$args" == *' DROP DATABASE '* || "$args" == *' CREATE DATABASE '* ]]; then
    exit 0
elif [[ "$args" == *' SELECT COUNT(*) '* ]]; then
    printf '1\n'
else
    printf 'EVENT import\n' >>"${STUB_LOG:-/dev/null}"
    cat >/dev/null
    [[ "${STUB_RESTORE_FAIL:-0}" == 0 ]] || exit 42
fi
STUB
chmod +x "$TEST_ROOT/bin/docker"
export PATH="$TEST_ROOT/bin:$REAL_PATH"
export MG5_BACKUP_DIRECTORY="$TEST_ROOT/backups"
export STUB_LOG="$TEST_ROOT/docker.log"
reset_log

assert_fails "$ROOT_DIR/scripts/database/restore.sh" "$TEST_ROOT/missing.sql.gz" --confirm-database moneyguru5
assert_no_replacement

printf 'not gzip' >"$TEST_ROOT/invalid.sql.gz"
printf '%064d  invalid.sql.gz\n' 0 >"$TEST_ROOT/invalid.sql.gz.sha256"
assert_fails "$ROOT_DIR/scripts/database/restore.sh" "$TEST_ROOT/invalid.sql.gz" --confirm-database moneyguru5
assert_no_replacement

printf 'safe sql\n' | gzip -c >"$TEST_ROOT/mismatch.sql.gz"
printf '%064d  mismatch.sql.gz\n' 0 >"$TEST_ROOT/mismatch.sql.gz.sha256"
assert_fails "$ROOT_DIR/scripts/database/restore.sh" "$TEST_ROOT/mismatch.sql.gz" --confirm-database moneyguru5
assert_no_replacement

assert_fails "$ROOT_DIR/scripts/database/verify-restore.sh" --database unsafe_database
STUB_DATABASE=mg5_restore_verification_same \
    assert_fails "$ROOT_DIR/scripts/database/verify-restore.sh" --database mg5_restore_verification_same

STUB_DUMP_FAIL=1 assert_fails "$ROOT_DIR/scripts/database/backup.sh" --output-directory "$TEST_ROOT/backups"
assert_no_partial_files "$TEST_ROOT/backups"

backup_output=$("$ROOT_DIR/scripts/database/backup.sh" --output-directory "$TEST_ROOT/backups")
backup_path=$(printf '%s\n' "$backup_output" | awk -F= '/^BACKUP_PATH=/{print substr($0, index($0, "=") + 1)}')
[[ -s "$backup_path" && -s "${backup_path}.sha256" ]] || fail 'valid backup artifacts were not created'
[[ "$(basename -- "$backup_path")" != *XXXXXX* ]] || fail 'backup filename is not collision-resistant'

assert_fails "$ROOT_DIR/scripts/database/restore.sh" "$backup_path"

reset_log
STUB_DATABASE='unsafe-name' \
    assert_fails "$ROOT_DIR/scripts/database/restore.sh" "$backup_path" --confirm-database unsafe-name
assert_no_replacement

reset_log
STUB_DUMP_FAIL=1 \
    assert_fails "$ROOT_DIR/scripts/database/restore.sh" "$backup_path" --confirm-database moneyguru5
assert_no_replacement

reset_log
STUB_CHARACTER_SET='utf8mb4;DROP' \
    assert_fails "$ROOT_DIR/scripts/database/restore.sh" "$backup_path" --confirm-database moneyguru5
assert_no_replacement

reset_log
STUB_COLLATION='utf8mb4_unicode_ci;DROP' \
    assert_fails "$ROOT_DIR/scripts/database/restore.sh" "$backup_path" --confirm-database moneyguru5
assert_no_replacement

reset_log
"$ROOT_DIR/scripts/database/restore.sh" "$backup_path" --confirm-database moneyguru5 >/dev/null
grep -Fq 'DROP DATABASE `moneyguru5`; CREATE DATABASE `moneyguru5` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' "$STUB_LOG" \
    || fail 'restore did not replace only the exact configured database with preserved settings'
[[ $(grep -c '^EVENT replace$' "$STUB_LOG") -eq 1 ]] \
    || fail 'restore did not perform exactly one database replacement'
replace_line=$(grep -n '^EVENT replace$' "$STUB_LOG" | cut -d: -f1)
import_line=$(grep -n '^EVENT import$' "$STUB_LOG" | tail -n 1 | cut -d: -f1)
[[ "$replace_line" -lt "$import_line" ]] || fail 'database replacement did not happen before import'

reset_log
if recreation_output=$(STUB_RECREATE_FAIL=1 \
    "$ROOT_DIR/scripts/database/restore.sh" "$backup_path" --confirm-database moneyguru5 2>&1); then
    fail 'database recreation failure was not propagated'
fi
grep -Fq 'PRE-RESTORE SAFETY BACKUP:' <<<"$recreation_output" \
    || fail 'database recreation failure did not report the safety backup'
if grep -q '^EVENT import$' "$STUB_LOG"; then
    fail 'import was attempted after database recreation failed'
fi

reset_log
if import_output=$(STUB_RESTORE_FAIL=1 \
    "$ROOT_DIR/scripts/database/restore.sh" "$backup_path" --confirm-database moneyguru5 2>&1); then
    fail 'database import failure was not propagated'
fi
reported_safety_backup=$(printf '%s\n' "$import_output" | sed -n 's/.*PRE-RESTORE SAFETY BACKUP: //p' | tail -n 1)
[[ -n "$reported_safety_backup" && -f "$reported_safety_backup" ]] \
    || fail 'import failure did not preserve and report the safety backup'
grep -q '^EVENT replace$' "$STUB_LOG" || fail 'import failure test did not replace the database first'
[[ $(grep -c '^EVENT import$' "$STUB_LOG") -eq 1 ]] \
    || fail 'import failure triggered an unexpected automatic recovery import'

verification_database=mg5_restore_verification_shell_test_12345
"$ROOT_DIR/scripts/database/verify-restore.sh" --database "$verification_database" >/dev/null
grep -Fq "DROP DATABASE IF EXISTS \`$verification_database\`" "$STUB_LOG" \
    || fail 'verification database cleanup was not invoked for the exact database'

printf 'PASS: operational backup script tests\n'
