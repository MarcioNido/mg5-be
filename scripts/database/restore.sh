#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
source "$SCRIPT_DIR/common.sh"

usage() { printf 'Usage: %s BACKUP.sql.gz [--confirm-database DATABASE]\n' "$0"; }

[[ $# -ge 1 ]] || { usage >&2; exit 2; }
backup_path=$1
shift
confirmed_database=''
while (($#)); do
    case "$1" in
        --confirm-database)
            [[ $# -ge 2 && -n "$2" ]] || { usage >&2; exit 2; }
            confirmed_database=$2
            shift 2
            ;;
        -h|--help) usage; exit 0 ;;
        *) usage >&2; exit 2 ;;
    esac
done

mg5_validate_backup "$backup_path"
target_database=$(mg5_database_name)
mg5_validate_database_identifier "$target_database" || {
    mg5_error 'The configured database name is empty or is not a safe MySQL identifier.'
    exit 1
}

printf 'WARNING: restoring replaces the current state of database "%s".\n' "$target_database"
if [[ -n "$confirmed_database" ]]; then
    [[ "$confirmed_database" == "$target_database" ]] || {
        mg5_error 'The --confirm-database value does not exactly match the configured database.'
        exit 1
    }
else
    [[ -t 0 && -t 1 ]] || {
        mg5_error "Non-interactive restore refused. Re-run with --confirm-database $target_database only after reviewing the target."
        exit 1
    }
    read -r -p "Type the target database name ($target_database) to continue: " typed_database
    [[ "$typed_database" == "$target_database" ]] || {
        mg5_error 'Confirmation did not match; restore cancelled.'
        exit 1
    }
fi

printf 'Creating a safety backup of the current database before restore...\n'
safety_output=$("$SCRIPT_DIR/backup.sh") || {
    mg5_error 'Safety backup failed; restore was not attempted.'
    exit 1
}
safety_backup=$(printf '%s\n' "$safety_output" | awk -F= '/^BACKUP_PATH=/{print substr($0, index($0, "=") + 1)}')
[[ -n "$safety_backup" && -f "$safety_backup" ]] || {
    mg5_error 'Safety backup did not report a valid path; restore was not attempted.'
    exit 1
}
printf 'Safety backup created: %s\n' "$safety_backup"

database_metadata=$(mg5_compose exec -T mysql sh -c '
    test -n "$MYSQL_ROOT_PASSWORD" || exit 64
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql \
        --user=root --batch --skip-column-names --execute="$1"
' sh "SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$target_database'") || {
    mg5_error "Could not read database character set and collation. Restore was not attempted. Safety backup: $safety_backup"
    exit 1
}
[[ -n "$database_metadata" && "$database_metadata" != *$'\n'* ]] || {
    mg5_error "Database character set/collation metadata is missing or ambiguous. Restore was not attempted. Safety backup: $safety_backup"
    exit 1
}
IFS=$'\t' read -r target_character_set target_collation extra_metadata <<<"$database_metadata"
[[ -n "$target_character_set" && -n "$target_collation" && -z "$extra_metadata" ]] || {
    mg5_error "Database character set/collation metadata is invalid. Restore was not attempted. Safety backup: $safety_backup"
    exit 1
}
mg5_validate_mysql_character_set "$target_character_set" || {
    mg5_error "Unsafe database character set was rejected. Restore was not attempted. Safety backup: $safety_backup"
    exit 1
}
mg5_validate_mysql_collation "$target_collation" || {
    mg5_error "Unsafe database collation was rejected. Restore was not attempted. Safety backup: $safety_backup"
    exit 1
}

printf 'Replacing only database "%s" while preserving character set and collation.\n' "$target_database"
if ! mg5_compose exec -T mysql sh -c '
    test -n "$MYSQL_ROOT_PASSWORD" || exit 64
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --user=root --execute="$1"
' sh "DROP DATABASE \`$target_database\`; CREATE DATABASE \`$target_database\` CHARACTER SET $target_character_set COLLATE $target_collation"; then
    mg5_error "DATABASE REPLACEMENT FAILED. No automatic rollback was attempted. PRE-RESTORE SAFETY BACKUP: $safety_backup"
    exit 1
fi

printf 'Database "%s" was recreated; importing the selected backup.\n' "$target_database"

if ! gzip -cd -- "$backup_path" | mg5_compose exec -T mysql sh -c '
    test -n "$MYSQL_DATABASE" && test -n "$MYSQL_ROOT_PASSWORD" || exit 64
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --user=root --database="$MYSQL_DATABASE"
'; then
    mg5_error "DATABASE IMPORT FAILED. No automatic rollback was attempted. PRE-RESTORE SAFETY BACKUP: $safety_backup"
    exit 1
fi

printf 'Restore completed for database "%s".\n' "$target_database"
