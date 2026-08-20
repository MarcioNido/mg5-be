# Phase 2 minimal tenancy

## Result

Phase 2 uses `spatie/laravel-multitenancy` 4.2.0 with one shared MySQL
database. It introduces the `personal` and `clinic` tenants, `tenant_user`
membership, tenant-scoped financial models, authenticated header selection,
and tenant-aware queue handlers. It does not introduce roles, tenant domains,
separate databases, or frontend changes.

The implementation used `broker-site-1-be` only as a reference for the Spatie
configuration, custom tenant model, finder, and queue setup. MG5 deliberately
replaces that application's domain lookup with authenticated slug selection.

## Database preflight and backup

Before the migration, the application used MySQL 8.0, database `moneyguru5`,
and Docker volume `mg5-be_mg5-mysql` (Compose volume key `mg5-mysql`). Exact
pre-migration counts were:

| Table | Rows |
| --- | ---: |
| accounts | 8 |
| balances | 50 |
| categories | 54 |
| files | 8 |
| rules | 48 |
| transactions | 1,025 |
| users | 1 |
| personal_access_tokens | 2 |

The validated backup is stored locally at
`storage/backup/mg5-moneyguru5-pre-phase2-20260820.sql`. The directory is
ignored by Git, the file mode is `0600`, its size is 151,407 bytes, and its
SHA-256 is:

```text
8a12b1ae694c68e2c26fd1b8d82b3334b1209214104468b0e9d7d21f552c08bd
```

The application MySQL user cannot run the MySQL 8 dump because it lacks
`RELOAD`/`FLUSH_TABLES`. The successful dump therefore used the container-local
root account with a single transaction, routines, triggers, events, hex blobs,
and GTID purging disabled. The dump was restored into a disposable MySQL 8
container; exact row counts matched and every restored table passed
`CHECK TABLE`.

## Resolution and membership

- `X-Tenant-Slug` is the canonical request header.
- `X-Tenant` is accepted as an alias.
- A missing header resolves to `personal` for compatibility with the existing
  backend clients. An empty or unknown slug returns 404.
- Sanctum authentication runs before membership validation.
- A user without a matching `tenant_user` row receives 403.
- Membership validation runs before route model binding, so bindings cannot
  resolve a model from the previously active tenant.
- `GET /api/tenants` lists the authenticated user's available tenants.

Pre-existing users received membership in both initial financial profiles so
the empty `clinic` profile is usable immediately. All pre-existing financial
rows were assigned only to `personal`. Newly registered users receive
`personal` membership by default; additional membership remains explicit.

## Financial isolation

`accounts`, `transactions`, `categories`, `rules`, `files`, and `balances`
contain a non-null foreign `tenant_id`. Their Eloquent models:

- apply a global current-tenant scope;
- return no records when no tenant is current;
- require a current tenant when creating data;
- prevent moving an existing row between tenants; and
- expose a `tenant` relationship.

Validation for account, category, and parent-category references includes the
current `tenant_id`. Transaction fingerprints and monthly balance uniqueness
now include `tenant_id`, allowing equivalent transactions and balance dates in
different tenants.

The legacy `accounts.account_number` key remains globally unique. Replacing it
with an internal account ID is explicitly deferred to Phase 3, so two tenants
cannot yet store the same account number. This preserves existing foreign keys
and route behavior during the tenancy-only phase.

Rollback restores the legacy global transaction and balance unique indexes. It
therefore assumes no equivalent cross-tenant rows were added after migration;
otherwise those rows must be reconciled before rollback.

## Queues and commands

Spatie queue propagation is enabled by default. `ProcessAllRules`,
`ProcessRule`, `ProcessFileUploadedListener`, and
`RecalculateBalancesListener` also implement the explicit `TenantAware` marker.
The `dispatch:process-all-rules` command now dispatches once inside each tenant
context instead of running without a tenant.

## Migration and verification

The migration was first exercised against a disposable MySQL 8 container
restored from the backup. It completed in approximately 436 ms. The validation
confirmed:

- two tenants and two memberships for the existing user;
- all 1,193 financial rows assigned to `personal`;
- non-null `tenant_id` on every financial table;
- successful `CHECK TABLE` for all affected tables;
- a successful rollback; and
- a successful second application with unchanged row counts.

The same migration was then applied to the backed-up local `moneyguru5` volume
as batch 2 in approximately 440 ms. Post-migration counts matched the backup
and all affected tables passed `CHECK TABLE`.

Automated verification uses SQLite in memory and includes 18 tests with 71
assertions. The added coverage proves header/default selection, membership
rejection, route-binding isolation, cross-tenant validation rejection,
fail-closed models, rule-job isolation, and explicit tenant-aware queue
handlers. A separate `migrate:fresh --seed` run also passed with the two tenant
seed records and both tenant memberships for the seeded administrator.
