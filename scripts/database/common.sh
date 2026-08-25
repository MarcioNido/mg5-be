#!/usr/bin/env bash

mg5_error() {
    printf 'Error: %s\n' "$*" >&2
}

mg5_compose() {
    docker compose "$@"
}

mg5_database_name() {
    mg5_compose exec -T mysql sh -c 'printf %s "$MYSQL_DATABASE"'
}

mg5_validate_database_identifier() {
    [[ "$1" =~ ^[A-Za-z0-9_]+$ ]] && ((${#1} <= 64))
}

mg5_validate_mysql_character_set() {
    [[ "$1" =~ ^[A-Za-z0-9_]+$ ]] && ((${#1} <= 64))
}

mg5_validate_mysql_collation() {
    [[ "$1" =~ ^[A-Za-z0-9_]+$ ]] && ((${#1} <= 64))
}

mg5_sha256() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | awk '{print $1}'
    elif command -v shasum >/dev/null 2>&1; then
        shasum -a 256 "$1" | awk '{print $1}'
    else
        mg5_error 'Neither sha256sum nor shasum is available.'
        return 1
    fi
}

mg5_validate_backup() {
    local backup_path=$1
    local checksum_path="${backup_path}.sha256"
    local expected_checksum actual_checksum

    [[ -f "$backup_path" ]] || { mg5_error "Backup file does not exist: $backup_path"; return 1; }
    [[ -r "$backup_path" ]] || { mg5_error "Backup file is not readable: $backup_path"; return 1; }
    [[ -s "$backup_path" ]] || { mg5_error "Backup file is empty: $backup_path"; return 1; }
    gzip -t -- "$backup_path" >/dev/null 2>&1 || {
        mg5_error "Backup is not a valid gzip stream: $backup_path"
        return 1
    }
    [[ -s "$checksum_path" && -r "$checksum_path" ]] || {
        mg5_error "Checksum sidecar is missing, empty, or unreadable: $checksum_path"
        return 1
    }

    expected_checksum=$(awk 'NR == 1 {print $1}' "$checksum_path")
    [[ "$expected_checksum" =~ ^[[:xdigit:]]{64}$ ]] || {
        mg5_error "Checksum sidecar is invalid: $checksum_path"
        return 1
    }
    actual_checksum=$(mg5_sha256 "$backup_path") || return 1
    actual_checksum=$(printf '%s' "$actual_checksum" | tr '[:upper:]' '[:lower:]')
    expected_checksum=$(printf '%s' "$expected_checksum" | tr '[:upper:]' '[:lower:]')
    [[ "$actual_checksum" == "$expected_checksum" ]] || {
        mg5_error "Checksum mismatch for backup: $backup_path"
        return 1
    }
}
