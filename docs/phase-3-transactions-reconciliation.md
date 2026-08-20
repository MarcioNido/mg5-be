# Phase 3: Transactions, imports, and reconciliation

## Outcome

Phase 3 replaces the legacy account-number-based financial schema with the
smallest clean model needed for a new MG5 database. Historical data was
explicitly declared disposable and was not migrated. The Phase 2 safety backup
at `storage/backup/mg5-moneyguru5-pre-phase2-20260820.sql` remains outside Git
and is not part of the initialization flow.

The standard Laravel migrations for users, password resets, failed jobs, and
personal access tokens remain. The old financial creation, alteration, balance,
and transitional tenancy migrations were consolidated into
`2026_08_20_000000_create_mg5_domain.php`. Rollback compatibility with the
discarded legacy data is intentionally not supported.

## Schema

- `tenants` and `tenant_user` provide the shared-database tenancy boundary.
- `accounts` uses an internal integer `id`. `account_number` is nullable and the
  `(tenant_id, account_number)` pair is unique. Currency defaults to `CAD`.
- `transactions` belongs to an account by `account_id`, stores exact
  `DECIMAL(19,4)` amounts, and may retain a simple `category_id`.
- `imports` records the uploaded batch, selected account, source, file hash,
  status, counters, and errors.
- `import_rows` records raw and normalized payloads, a stable bank-line
  fingerprint, occurrence number, processing status, errors, and its resulting
  transaction.
- `imported_movements` owns the persistent deduplication identity and resulting
  transaction. Its unique key combines tenant, account, source, movement
  fingerprint, and occurrence.
- `transaction_splits` records management categories without changing the bank
  transaction amount.
- `match_suggestions` records ambiguous pending/imported candidates and their
  review status.
- `reconciliations` records statement date, entered bank balance, calculated
  balance, and the timestamp at which they agree.
- `rules` now scopes its optional account relation by `account_id`.

All financial tables require `tenant_id`. Domain models fail closed without a
current tenant. Composite foreign keys enforce same-tenant account, category,
transaction, import, split, suggestion, and reconciliation relations. API route
binding happens after tenant selection and membership validation.

Seeders create only `personal`, `clinic`, a development administrator with both
memberships, and three small management categories in each tenant. They create
no bank accounts and contain no real bank identifiers. The clinic categories
are management conveniences and do not replace the external accountant.

## Transaction lifecycle

Bank state is represented by PHP-backed string enums:

- `pending`: a manual commitment or projection that is not in confirmed cash.
- `posted`: a bank-confirmed or imported movement included in confirmed cash.

Origins are `manual`, `csv`, and `system`. Manual API entries default to
`pending`; imported rows are `posted`. `posted_at` is populated only on the
transition to bank-confirmed state. Amounts use decimal strings and integer
four-decimal units in domain equality and summation code; floats are not used
for financial decisions.

## CSV imports and duplication

The RBC and Triangle readers retain their existing header recognition and field
normalization. Readers yield raw plus normalized rows; they no longer write
transactions themselves. `CsvImportService` owns the transaction boundary,
per-row error capture, status counters, fingerprints, and matching.

The file hash is scoped by tenant and account and makes exact file re-upload
idempotent. When an upload resolves to an existing batch, its newly stored file
is deleted immediately. A movement fingerprint includes tenant, selected
account, available bank reference (RBC cheque number or Triangle `REF`), bank
date, exact amount, and normalized description.

Identical fingerprints receive deterministic occurrence numbers within each
file. Thus two genuinely identical rows in one statement become occurrence 1
and occurrence 2 and create two transactions. Reimporting those movements in
the same or a differently encoded file resolves the same two identities and
creates no transactions. `imported_movements` enforces the identity with a
database unique constraint; processing uses `insertOrIgnore` followed by
`lockForUpdate`, so concurrent batches cannot claim the same occurrence twice.
Duplicate import rows point at the existing movement and transaction.
Malformed rows are stored as `failed`; other valid rows in the batch continue.
The queued file listener and categorization jobs implement Spatie's
`TenantAware` contract.

No automatic bank connection is included.

## Pending-to-import matching

`TransactionMatchingService` searches only the current tenant and selected
account for pending entries with the same exact amount, a date within three
days, and description confidence of at least 0.75. Description comparison is
case-insensitive and punctuation-normalized; an absent description does not
reduce confidence.

A single high-confidence candidate is updated in place to `posted`. Its
category, notes, description, and splits remain intact while the imported date,
account, amount, and posting timestamp become authoritative.

If multiple candidates qualify, the imported movement is created as the one
posted transaction so confirmed bank cash remains complete. The manual entries
stay pending and a suggestion is created for each candidate. Therefore a
pending review never creates two definitive posted movements. Confirming a
suggestion promotes the selected manual transaction, transfers the import-row
link, preserves its enrichment, and soft-deletes the temporary imported
transaction. Rejecting every suggestion keeps the imported transaction and
marks the row imported.

Confirmation and rejection lock and reload the suggestion and import row inside
one database transaction. Only `pending` suggestions on a `needs_review` row
are accepted. Confirmation additionally requires the candidate to remain
`pending`, the imported transaction to exist, and the persistent movement
identity to still point at it. Repeated or competing calls fail without changing
an already confirmed/rejected result.

Once any transaction is linked through `import_rows`/`imported_movements`, its
account, bank date, amount, status, and direct deletion are protected regardless
of whether its origin is CSV or it began as a matched manual transaction.
Category, notes, and valid splits remain editable.

## Category splits

`TransactionSplitService` replaces splits atomically. Every category must be in
the current tenant and the sum of signed split amounts must equal the signed
transaction amount exactly to four decimals. Positive and negative totals use
the same rule. Splits and the optional simple `category_id` are management
metadata: they never change the bank amount or reconciliation.

## Reconciliation

Each account has an `opening_balance` and optional `opening_balance_date`.
`ReconciliationService` calculates:

```text
opening_balance
+ posted transactions after opening_balance_date
  and through statement_date
= calculated_balance
```

Pending transactions, splits, categories, descriptions, and notes are excluded.
A reconciliation is valid exactly while `entered_bank_balance` equals the
current `calculated_balance`. Inserts, deletes, or changes to account, date,
amount, or posted state recalculate affected statement dates. Transactions after
a statement date do not affect that reconciliation. The latest row whose
calculated and entered balances still agree is the account's reconciled-through
date.

Changes to `accounts.opening_balance` or `opening_balance_date` invoke the same
domain recalculation service from the Account model lifecycle, so updates made
outside HTTP controllers also invalidate or revalidate every affected
reconciliation.

Bank dates are interpreted in `America/Toronto` through
`APP_BUSINESS_TIMEZONE`; application timestamps retain the existing UTC storage
strategy.

## API

Authenticated, tenant-selected routes provide account CRUD; transaction list,
filters and CRUD; CSV upload and import status; pending match review,
confirmation and rejection; and account reconciliation creation, history, and
latest-valid lookup. Requests validate account and category existence inside
the current tenant. Controllers delegate matching, split, import, and
reconciliation rules to domain services.

## Verification

The Phase 3 suite covers tenant isolation and duplicate account numbers across
tenants; manual pending creation; posted-only balances; RBC and Triangle
normalization and idempotency; unique and ambiguous matching; confirmation and
rejection; preservation of manual enrichment; exact splits; and retroactive
versus later reconciliation changes.

Final validation commands:

```bash
composer validate --strict
composer audit
vendor/bin/pint --dirty
php artisan test
./vendor/bin/sail artisan test
./vendor/bin/sail artisan migrate:fresh --seed
git diff --check
```

Before the destructive MySQL initialization, verify that `APP_ENV` is not
`production`, the selected database is exactly `moneyguru5`, and the Docker
Compose project is this MG5 backend. No volume removal or prune is part of this
phase.

Results on 2026-08-20:

- `composer validate --strict`: passed.
- `composer audit`: passed; no security advisories found.
- `vendor/bin/pint --dirty`: passed after formatting only changed PHP files.
- `php artisan test`: 33 tests and 164 assertions passed on the host.
- `./vendor/bin/sail artisan test`: 33 tests and 164 assertions passed in the
  PHP 8.4 container.
- `./vendor/bin/sail artisan migrate:fresh --seed`: passed against only
  `moneyguru5` after confirming `APP_ENV=local`, Compose project `mg5-be`, and
  MySQL 8.0.32.
- MySQL schema inspection confirmed all monetary columns as `DECIMAL(19,4)`.
- The initialized financial tables contain zero accounts, transactions,
  imports, imported movements, import rows, and reconciliations; `personal`
  and `clinic` plus their administrator memberships are present.
- `git diff --check`: passed.
- The Phase 2 backup remains ignored and intact; no SQL backup or secret was
  added to Git.
