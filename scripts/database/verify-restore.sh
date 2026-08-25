#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
source "$SCRIPT_DIR/common.sh"

usage() { printf 'Usage: %s [--database mg5_restore_verification_UNIQUE_NAME]\n' "$0"; }

verification_database=${MG5_VERIFICATION_DATABASE:-}
while (($#)); do
    case "$1" in
        --database)
            [[ $# -ge 2 && -n "$2" ]] || { usage >&2; exit 2; }
            verification_database=$2
            shift 2
            ;;
        -h|--help) usage; exit 0 ;;
        *) usage >&2; exit 2 ;;
    esac
done

if [[ -z "$verification_database" ]]; then
    verification_database="mg5_restore_verification_$(date -u '+%Y%m%dT%H%M%SZ')_$$_${RANDOM}"
fi
[[ "$verification_database" =~ ^mg5_restore_verification_[A-Za-z0-9_]+$ ]] \
    && mg5_validate_database_identifier "$verification_database" || {
        mg5_error 'Verification database name is unsafe or lacks the required mg5_restore_verification_ prefix.'
        exit 1
    }

configured_database=$(mg5_database_name)
mg5_validate_database_identifier "$configured_database" || {
    mg5_error 'The configured application database name is empty or unsafe.'
    exit 1
}
[[ "$verification_database" != "$configured_database" ]] || {
    mg5_error 'Verification database must not equal the configured application database.'
    exit 1
}

printf 'Configured application database (read-only source): %s\n' "$configured_database"
printf 'Disposable verification database: %s\n' "$verification_database"

created=0
cleanup() {
    if ((created)); then
        if [[ "$verification_database" =~ ^mg5_restore_verification_[A-Za-z0-9_]+$ ]] \
            && [[ "$verification_database" != "$configured_database" ]]; then
            mg5_compose exec -T mysql sh -c '
                MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --user=root --execute="$1"
            ' sh "DROP DATABASE IF EXISTS \`$verification_database\`" >/dev/null
        else
            mg5_error 'Cleanup refused because the verification database name failed validation.'
            return 1
        fi
    fi
}
trap cleanup EXIT HUP INT TERM

backup_output=$("$SCRIPT_DIR/backup.sh")
printf '%s\n' "$backup_output"
backup_path=$(printf '%s\n' "$backup_output" | awk -F= '/^BACKUP_PATH=/{print substr($0, index($0, "=") + 1)}')
[[ -n "$backup_path" ]] || { mg5_error 'Backup command did not report a backup path.'; exit 1; }
mg5_validate_backup "$backup_path"

mg5_compose exec -T mysql sh -c '
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --user=root --execute="$1"
' sh "CREATE DATABASE \`$verification_database\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
created=1

gzip -cd -- "$backup_path" | mg5_compose exec -T mysql sh -c '
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --user=root --database="$1"
' sh "$verification_database"

read_query() {
    mg5_compose exec -T mysql sh -c '
        MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --user=root --batch --skip-column-names --execute="$1"
    ' sh "$1"
}

source_table_count=$(read_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$configured_database' AND table_type = 'BASE TABLE'")
restored_table_count=$(read_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$verification_database' AND table_type = 'BASE TABLE'")
source_migration_count=$(read_query "SELECT COUNT(*) FROM \`$configured_database\`.\`migrations\`")
restored_migration_count=$(read_query "SELECT COUNT(*) FROM \`$verification_database\`.\`migrations\`")

[[ "$source_table_count" =~ ^[0-9]+$ && "$source_table_count" -gt 0 ]] || {
    mg5_error 'Configured database table count is invalid.'
    exit 1
}
[[ "$source_table_count" == "$restored_table_count" ]] || {
    mg5_error 'Restored table count does not match the configured database.'
    exit 1
}
[[ "$source_migration_count" =~ ^[0-9]+$ && "$source_migration_count" -gt 0 ]] || {
    mg5_error 'Configured database has no migrations-table records.'
    exit 1
}
[[ "$source_migration_count" == "$restored_migration_count" ]] || {
    mg5_error 'Restored migrations-table count does not match the configured database.'
    exit 1
}

printf 'Restore drill passed: %s base tables and %s migration records matched.\n' \
    "$restored_table_count" "$restored_migration_count"
printf 'The configured application database was not altered.\n'
