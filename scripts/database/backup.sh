#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
REPOSITORY_ROOT=$(cd -- "$SCRIPT_DIR/../.." && pwd)
source "$SCRIPT_DIR/common.sh"

usage() { printf 'Usage: %s [--output-directory DIRECTORY]\n' "$0"; }

output_directory=${MG5_BACKUP_DIRECTORY:-$REPOSITORY_ROOT/storage/backup}
while (($#)); do
    case "$1" in
        --output-directory)
            [[ $# -ge 2 && -n "$2" ]] || { usage >&2; exit 2; }
            output_directory=$2
            shift 2
            ;;
        -h|--help) usage; exit 0 ;;
        *) usage >&2; exit 2 ;;
    esac
done

mkdir -p -- "$output_directory"
[[ -d "$output_directory" && -w "$output_directory" ]] || {
    mg5_error "Backup directory is not writable: $output_directory"
    exit 1
}

umask 077
timestamp=$(date -u '+%Y%m%dT%H%M%SZ')
temporary_path=$(mktemp "$output_directory/.mg5-backup-${timestamp}.XXXXXX")
temporary_checksum_path="${temporary_path}.sha256"
cleanup() { rm -f -- "$temporary_path" "$temporary_checksum_path"; }
trap cleanup EXIT HUP INT TERM

suffix=${temporary_path##*.mg5-backup-${timestamp}.}
backup_path="$output_directory/mg5-${timestamp}-${suffix}.sql.gz"
checksum_path="${backup_path}.sha256"

if ! mg5_compose exec -T mysql sh -c '
    test -n "$MYSQL_DATABASE" && test -n "$MYSQL_ROOT_PASSWORD" || exit 64
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump \
        --user=root --single-transaction --quick --routines --events --triggers \
        --hex-blob --set-gtid-purged=OFF --no-tablespaces \
        --default-character-set=utf8mb4 -- "$MYSQL_DATABASE"
' | gzip -c >"$temporary_path"; then
    mg5_error 'Database dump failed; the incomplete backup was removed.'
    exit 1
fi

[[ -s "$temporary_path" ]] || { mg5_error 'Database dump produced an empty backup.'; exit 1; }
gzip -t -- "$temporary_path" >/dev/null 2>&1 || {
    mg5_error 'Database dump produced an invalid gzip stream.'
    exit 1
}
uncompressed_bytes=$(gzip -cd -- "$temporary_path" | wc -c | tr -d '[:space:]')
[[ "$uncompressed_bytes" =~ ^[0-9]+$ && "$uncompressed_bytes" -gt 0 ]] || {
    mg5_error 'Database dump contains no SQL data.'
    exit 1
}

checksum=$(mg5_sha256 "$temporary_path")
printf '%s  %s\n' "$checksum" "$(basename -- "$backup_path")" >"$temporary_checksum_path"
chmod 600 "$temporary_path" "$temporary_checksum_path" 2>/dev/null || true
mv -- "$temporary_path" "$backup_path"
if ! mv -- "$temporary_checksum_path" "$checksum_path"; then
    rm -f -- "$backup_path"
    mg5_error 'Could not finalize the checksum; the incomplete backup was removed.'
    exit 1
fi
trap - EXIT HUP INT TERM

printf 'Backup created: %s\n' "$backup_path"
printf 'Checksum created: %s\n' "$checksum_path"
printf 'BACKUP_PATH=%s\n' "$backup_path"
printf 'CHECKSUM_PATH=%s\n' "$checksum_path"
