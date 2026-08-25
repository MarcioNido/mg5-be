# Phase 5E.1: Categories and automatic rules API

## Outcome and purpose

Phase 5E.1 provides tenant-aware administration for management categories and
literal description rules. Categories help Personal and Clinic users understand
cash activity; they are not a general ledger, chart of accounts, or
double-entry accounting system.

All endpoints require Sanctum authentication, tenant selection, and membership.
Normal route binding and relation validation fail closed across tenants.

## Category kinds and hierarchy

The public category kinds are:

- `income`: management revenue and inflows;
- `expense`: operating, financing, tax, and capital cash outflows;
- `transfer`: debt principal, owner activity, savings movements, and transfers
  excluded from operating income and expense.

The hierarchy has at most three levels: management group, normal category, and
optional detail. Roots have `parent_id=null` and level 1. Clients send only
`name`, `type`, and nullable `parent_id`; the server derives levels. A move is
transactional, rejects self-parenting and descendant cycles, verifies the
entire subtree remains within level 3, and updates all descendant levels.
Names are trimmed, non-empty, and case-insensitively unique among siblings in
the same tenant. The same name may occur under another parent or tenant. A
category's type is independently editable and is not propagated.

The normalization migration changes legacy types in place without changing
category IDs or relationships: `deductions`, `fixed expenses`, `variable
expenses`, and `expense` become `expense`; `financial transactions` becomes
`transfer`; `income` remains `income`. The migration is intentionally
irreversible because the separate legacy expense labels cannot be reconstructed
after normalization.

## Category API

- `GET /api/categories`: non-paginated flat list ordered by type, lowercase
  name, then ID.
- `POST /api/categories`: creates from `name`, `type`, and nullable `parent_id`;
  returns 201.
- `GET /api/categories/{category}`: returns detail and direct children.
- `PATCH|PUT /api/categories/{category}`: updates any supplied public fields;
  returns 200.
- `DELETE /api/categories/{category}`: soft-deletes an unused leaf; returns 204.

The default resource remains the Phase 5B shape: `id`, `name`, `type`, `level`,
and nullable public `parent` summary. Detail may additionally contain direct
children. Tenant IDs, deletion metadata, and raw models are not exposed.

Deletion returns 422 under `category` if the category has active children, is
used by any transaction or split, or is targeted by an active rule. Historical
references from soft-deleted transactions continue to block deletion. An
unused tree can be removed bottom-up because an already soft-deleted child no
longer blocks its otherwise unused parent. Deletion never cascades or orphans
management data. Soft-deleted categories disappear from normal routes and
cannot be selected as parents or referenced by new transactions, splits, or
rules.

## Automatic rule API

The public write contract is:

- `match_text`: required trimmed literal text, at most 120 characters;
- `account_id`: nullable account ID; null means all tenant accounts;
- `category_id`: required category ID.

The database retains the `content` column, with an explicit one-to-one mapping
to public `match_text`. Clients do not send SQL patterns or percent signs around
text. Matching is a case-insensitive description-contains operation. Percent,
underscore, exclamation, and backslash characters are treated as literal user
text and do not broaden the SQL match.

A focused migration removes one pair of surrounding percent signs from
non-empty legacy rule patterns (for example, `%MARKET%` becomes `MARKET`) so
their former contains intent survives the move to literal semantics. Other
content is preserved in place and rule IDs and historical categorizations are
unchanged.

- `GET /api/rules`: standard pagination, default 25 and maximum 50; supports
  positive `page`, `account_id`, `category_id`, and case-insensitive `search`.
- `POST /api/rules`: creates a rule and returns 201.
- `GET /api/rules/{rule}`: returns one rule.
- `PATCH|PUT /api/rules/{rule}`: updates supplied fields and returns 200.
- `DELETE /api/rules/{rule}`: soft-deletes and returns 204.

Rules are ordered by lowercase `match_text`, then ID, and pagination links keep
the active query. Cross-tenant or soft-deleted account/category filters and
references return 422. The explicit rule resource exposes only `id`,
`match_text`, nullable public account summary, public category/parent summary,
and ISO `created_at` and `updated_at` timestamps. It never exposes tenant IDs,
account numbers, internal patterns, deletion state, or queue details.

## Processing semantics

Normal processing considers only transactions with both a null direct
`category_id` and no splits. It changes only `category_id`; it never overwrites
an existing direct category or creates a direct-category-plus-splits state.
Account-specific rules are restricted to that account; global rules cover all
accounts in the current tenant.

`ProcessAllRules` is explicitly tenant-aware and evaluates active rules in
ascending ID order in one job. Once a transaction is categorized, subsequent
rules cannot overwrite it, so the first matching rule wins deterministically.
The trusted console force option can overwrite an existing direct category but
still excludes transactions with splits. The HTTP API never requests force.

Creating or updating a rule queues safe all-rules processing for the selected
tenant. Processing is asynchronous under a queued production connection.
Updating or deleting a rule does not undo historical categorizations.

## Recommended plans and installation

`CategorySeeder` selects a plan from the current tenant slug. Clinic receives
Revenue, Direct clinical costs, Staffing, Occupancy, Administration, Financing
costs, Taxes and reserves, Capital expenditures, Debt principal and transfers,
and Owner transactions with the documented Phase 5E children. Personal
receives Income, Housing, Food, Transportation, Health, Personal and lifestyle,
Taxes and financial costs, Savings and investments, and Debt principal and
transfers with its own practical children.

The seeder creates only categories. It creates no accounts, account numbers,
transactions, or rules. Exact `(parent_id, name)` lookup makes installation
tenant-aware, repeatable, additive, and non-destructive: it does not delete,
rename, move, or overwrite an existing category. `DatabaseSeeder` also keeps
the admin user and memberships idempotently.

`php artisan db:seed --class=CategorySeeder` is independently executable. If a
tenant is already current, it seeds only that tenant. With no current tenant,
it locates `personal` and `clinic`, installs each plan in its own tenant
context, and then leaves no tenant current. Missing configured tenants and
unknown tenant slugs are harmless. `DatabaseSeeder` invokes this same behavior
once after creating tenants and preserving the administrator memberships. Do
not use a destructive reset to install plans.

## Phase 5E.2 frontend handoff

The frontend should build its category tree from the flat list, send
`parent_id` rather than nested parent objects, use only canonical category
kinds, and treat rule text literally. It should explain deletion blockers,
global versus account-specific rules, queued reprocessing, first-match-wins
precedence by creation order, and the absence of historical undo.

## Limitations

There is no rule priority field beyond ascending ID, preview/dry-run, bulk
category move, rule execution history, historical undo, formal accounting
mapping, or automatic type propagation. The recommended plans are installed by
an explicit seeder action and are not silently applied to an existing database.
