# MG5 Target Architecture

## Repositories

- `mg5-be`: Laravel API, persistence, imports, categorization, reconciliation,
  and management calculations.
- `mg5-fe`: Next.js application rebuilt from the licensed Minimals
  `simple-next-ts` 5.0 template, with only MG5-specific pages and components.

## Backend modernization

The current backend is Laravel 9. Before adding tenancy, it should be upgraded
incrementally through supported Laravel major versions. Characterization tests
must protect the existing CSV import, categorization, transaction, and balance
behaviour during the upgrade.

The target environment is:

- A currently supported Laravel version.
- PHP 8.4 for local and container environments.
- Spatie Laravel Multitenancy v4.
- MySQL with one shared database.

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

Rules continue to categorize imported transactions automatically. A rule may
use normalized description content and optionally be limited to an account.
Uncategorized transactions remain visible as an action item.

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
