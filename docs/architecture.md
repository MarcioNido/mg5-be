# MG5 Target Architecture

## Repositories

- `mg5-be`: Laravel API, persistence, imports, categorization, reconciliation,
  and management calculations.
- `mg5-fe`: Next.js application rebuilt from the licensed Minimals
  `simple-next-ts` 5.0 template, with only MG5-specific pages and components.

## Backend modernization

The backend runs on Laravel 13 and PHP 8.4. The framework was upgraded one major
version at a time from Laravel 9, with characterization tests protecting the
existing authentication, CSV import, categorization, transaction, and balance
behaviour throughout the upgrade.

The current environment is:

- Laravel 13.
- PHP 8.4 for local and container environments.
- MySQL with one shared database.

Spatie Laravel Multitenancy v4.2 is configured for shared-database tenancy.

## Tenancy

MG5 uses tenants internally to isolate financial contexts. The initial tenants
are:

- `personal`
- `clinic`

The UI may present them as financial profiles rather than using the technical
term tenant.

Unlike the domain-based tenancy in Leardi's `broker-site-1-be`, MG5 determines
the current tenant from the authenticated user's selection. A request may send a
tenant slug in a header. Middleware must verify that the authenticated user
belongs to that tenant before making it current.

The minimal tenancy model is:

- `tenants`
- `tenant_user`

Roles are not required initially. Domain models that contain financial data are
scoped by `tenant_id`, including accounts, transactions, categories, rules,
imports, balances, and reconciliations.

Tenant-aware queued jobs are required for CSV processing, rule processing, and
balance recalculation.

Authenticated API requests select a tenant with `X-Tenant-Slug`; `X-Tenant`
is accepted as a compatibility alias. Requests without either header select
`personal` until the tenant selector is added in the frontend phase. The
membership middleware runs before route model binding, and all financial models
fail closed when no current tenant exists.

See [Phase 2 minimal tenancy](phase-2-minimal-tenancy.md) for migration,
backup, compatibility, and verification details.

Phase 3 intentionally replaces the disposable legacy financial schema with a
clean schema. Every financial relation uses an internal ID and composite
`tenant_id` foreign keys where the database can enforce tenant consistency.
`account_number` is nullable import metadata and is unique only within a tenant.
See [Phase 3 transactions and reconciliation](phase-3-transactions-reconciliation.md).

CSV deduplication uses a persistent `imported_movements` identity rather than a
unique transaction-shaped row. The identity includes available bank references
and an occurrence number, allowing legitimate identical statement rows while a
database constraint and row lock keep reimports and concurrent batches
idempotent.

## Transaction model

Transactions have two bank states:

- `pending`: entered manually and expected to appear at the bank later.
- `posted`: confirmed by CSV import or another bank source.

Transaction origin is recorded as manual, CSV import, or system. Posted
transactions are used for confirmed cash reporting. Pending transactions are
shown as commitments or projected cash and are excluded from reconciled bank
balances.

Imported transactions remain the source of truth for posted date, bank account,
and amount. User categories, notes, and splits can enrich them.

## Manual-to-import matching

When importing a CSV row, MG5 first looks for a compatible pending transaction
using:

- Same tenant and bank account.
- Same amount.
- A nearby date.
- A compatible description when available.

A unique, high-confidence candidate can be matched automatically. Ambiguous
candidates require user confirmation. Matching must preserve manual categories,
notes, and splits and must not create a second visible transaction.

## Categorization

Rules categorize transactions with case-insensitive literal description text
and may optionally be limited to an account. Active rules run in ascending ID
order, and the first match may update only a transaction with no direct
category and no splits. Uncategorized transactions remain visible as an action
item.

A transaction may be split across multiple management categories as long as the
split total equals the bank transaction amount. Splits do not affect bank
reconciliation.

Examples include separating loan principal from interest and separating regular
salary from exam-based compensation.

## Bank reconciliation

Reconciliation is deliberately simple. For each account, the user enters a bank
balance and statement date. MG5 compares it with:

```text
previous confirmed balance
+ posted transactions since that confirmation
= calculated balance
```

A reconciliation is valid whenever its bank balance equals its currently
calculated balance. No separate audit workflow or invalidation metadata is
required.

The calculation is made independently from the account opening checkpoint:

```text
account opening balance
+ posted transactions after opening_balance_date and through statement_date
= calculated balance
```

This is equivalent to advancing from prior confirmed checkpoints while making
retroactive recalculation deterministic even when an earlier checkpoint becomes
invalid.

The minimal reconciliation record contains:

- Account.
- Reconciliation date.
- Entered bank balance.
- Calculated balance.
- Reconciliation timestamp.

When a posted transaction on or before a reconciliation date is inserted,
deleted, or has its amount, date, or account changed, affected balances are
recalculated. The application then reports the latest reconciliation whose
balance still matches.

Changes to category, description, notes, attachments, or category splits do not
affect reconciliation when the transaction total, date, account, and posted
state remain unchanged.

Updating an account's opening balance or opening date recalculates all of its
reconciliations through the same domain service used for transaction mutations.

## Management adjustments

Adjustments that do not represent a bank movement are stored separately from
bank transactions and never participate in bank reconciliation. The initial
adjustment types may be limited to:

- Revenue receivable.
- Expense payable.
- Estimated tax.
- Other management adjustment.

Reports show cash results separately from adjustments and may present an
adjusted management result.

## Frontend direction

The new frontend should use the Minimals `simple-next-ts` 5.0 application as its
design and structural source. Components from the full template or legacy MG5
are copied only when they support an active MG5 workflow.

Initial routes are limited to login, dashboard, transactions, imports and
reconciliation, accounts, categories, rules, and management adjustments.
Legacy URLs may redirect to their replacements.
