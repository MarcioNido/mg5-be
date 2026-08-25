## Money Guru

### Database backups

Create a compressed, checksummed backup with:

```sh
./scripts/database/backup.sh
```

Backups are local sensitive data, not a substitute for encrypted off-laptop
storage. See [Phase 7A operational backups](docs/phase-7a-operational-backups.md)
for checksum verification, guarded restore, restore-drill, and recovery steps.

### Secure local administrator bootstrap

Database seeding creates only the Personal and Clinic tenants and their
recommended category plans. It never creates a user or a default credential.
After establishing a clean baseline, create the administrator interactively:

```sh
./vendor/bin/sail artisan mg5:bootstrap-admin
```

Enter the password only in the hidden prompts. Never share credentials in an AI
prompt, place them in a command argument, or commit them. See the
[Phase 7B real-data pilot guide](docs/phase-7b-real-data-pilot.md) before adding
accounts or importing statements.
