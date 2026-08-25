# Phase 7A: operational database backups

Phase 7A provides repository-owned, host-side commands for backing up and
restoring the local MySQL database. The commands use the Compose service name
`mysql` and credentials already present in the container environment. They do
not contain or print credentials.

## Create and verify a backup

From the repository root, run:

```sh
./scripts/database/backup.sh
```

The command writes a uniquely named `mg5-*.sql.gz` file and its
`.sql.gz.sha256` sidecar under `storage/backup/`. It uses a transactional MySQL
8/InnoDB dump, writes through a restricted temporary file, validates the gzip
stream and contents, and moves the artifacts into place only after success. It
never deletes older backups.

Backup and checksum paths are printed on success. The backup command performs
built-in gzip validation and creates the SHA-256 checksum. Verify it again at
any time from the repository root with this exact command, replacing the name:

```sh
cd storage/backup && shasum -a 256 -c mg5-YYYYMMDDTHHMMSSZ-UNIQUE.sql.gz.sha256
```

On a system with GNU coreutils, `sha256sum -c` can be used instead. Keep each
backup beside its checksum sidecar.

`storage/backup/` is ignored by Git. Ignoring a file does not make it safe: a
database backup can contain highly sensitive personal, clinic, and financial
data. A backup stored only on the same laptop is not sufficient. Keep at least
one encrypted, access-controlled copy outside Docker and outside the laptop.

## Guarded restore

Restoring replaces the current database state. Review the target and backup,
then run:

```sh
./scripts/database/restore.sh storage/backup/mg5-YYYYMMDDTHHMMSSZ-UNIQUE.sql.gz
```

The command rejects missing, empty, unreadable, invalid-gzip, or
checksum-mismatched input. It prints the configured target database and
requires its exact name to be typed interactively. Immediately after
confirmation it creates and reports a fresh safety backup; if that backup
fails, restoration is refused. It then reads and validates the existing
database character set and collation, drops and recreates only the exact
configured database with those settings, and imports the selected dump. This
exact replacement removes tables or other objects that are not present in the
backup instead of leaving stale objects behind.

If database recreation or import fails, the command exits unsuccessfully and
prominently reports the pre-restore safety-backup path. It does not
automatically restore that safety backup; recovery remains an explicit operator
decision.

Non-interactive use is refused unless the exact configured database is
provided deliberately:

```sh
./scripts/database/restore.sh storage/backup/mg5-YYYYMMDDTHHMMSSZ-UNIQUE.sql.gz --confirm-database moneyguru5
```

Replace `moneyguru5` if the configured database has another name. Supplying
this flag is equivalent to answering the destructive confirmation prompt and
must never be added to application startup or unattended automation.

## Disposable restore drill

The drill creates a fresh backup, verifies it, restores only into a uniquely
named database with the required `mg5_restore_verification_` prefix, compares
the base-table and migrations-record counts, and drops that exact disposable
database during cleanup:

```sh
./scripts/database/verify-restore.sh
```

The command prints the exact disposable database name before creating it. For
an independently reviewed name, pass a unique safe identifier explicitly:

```sh
./scripts/database/verify-restore.sh --database mg5_restore_verification_YYYYMMDDTHHMMSSZ_unique
```

The drill refuses an unsafe name, a name without the prefix, or the configured
application database name. It queries the configured database only for the
dump and aggregate structural counts; it never drops or alters it. No SQL dump
contents or financial rows are printed.

## Minimal operating routine

- Back up before bulk imports or risky maintenance.
- Back up after a successful reconciliation cycle.
- Periodically verify and copy a backup to encrypted off-laptop storage.
- Perform an occasional disposable restore drill.

## Docker cleanup risk matrix

| Operation | Named database volume risk |
| --- | --- |
| Prune build cache | Does not delete database volumes. |
| Prune images | Does not delete database volumes. |
| Prune containers | Does not normally delete named volumes. |
| `docker volume prune -a` | Can destroy the database volume. |
| `docker compose down -v` | Deletes Compose volumes and can destroy the database. |
| Delete the named `mg5-mysql` volume | Destroys the database stored there. |
| Reset Docker Desktop data | Can destroy all local Docker data, including the database. |

No prune command or Docker volume should be treated as a backup strategy.

## Recovery after volume loss

1. Locate a trusted `.sql.gz` backup and its `.sha256` sidecar, preferably from
   encrypted off-laptop storage.
2. Copy both files into `storage/backup/` without opening or editing them.
3. Start the Compose services and allow MySQL to initialize a new database.
4. Run the guarded restore command; it verifies the checksum and gzip stream
   before confirmation.
5. Confirm the exact configured target database and allow the safety backup and
   restore to finish.
6. Run application tests or smoke checks, sign in, and verify expected periods,
   accounts, imports, and reconciliation state without exposing records in logs.
7. Create and export a new verified, encrypted off-laptop backup.

## Limitations

This phase does not provide cloud backup, encryption, scheduling, automated
retention, production deployment, or production disaster recovery. Operators
remain responsible for securely copying and retaining verified backups.
