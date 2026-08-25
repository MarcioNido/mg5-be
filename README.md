## Money Guru

### Database backups

Create a compressed, checksummed backup with:

```sh
./scripts/database/backup.sh
```

Backups are local sensitive data, not a substitute for encrypted off-laptop
storage. See [Phase 7A operational backups](docs/phase-7a-operational-backups.md)
for checksum verification, guarded restore, restore-drill, and recovery steps.
