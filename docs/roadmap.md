# MG5 Roadmap

## Delivery approach

Work is divided into small, reviewable phases. Each phase should preserve a
working application and use focused tests. Major phases are good boundaries for
separate Codex tasks and Git branches; small fixes within a phase should remain
in the same task.

## Phase 1: Backend safety and framework upgrade

**Status: Complete — Laravel 13 / PHP 8.4.**

1. Add characterization tests for authentication, RBC and Triangle CSV imports,
   duplicate prevention, categorization rules, transaction CRUD, and balance
   calculations.
2. Align the local and Sail PHP versions on PHP 8.4.
3. Upgrade Laravel one major version at a time from Laravel 9 to the selected
   supported target.
4. Upgrade Sanctum, PHPUnit, Collision, Sail, and related dependencies only as
   required by each Laravel step.
5. Run the characterization tests after every major upgrade.

This phase should be executed in a separate task because dependency upgrades can
produce substantial diagnostic output and should remain isolated from product
feature work.

## Phase 2: Minimal tenancy

**Status: Complete — Spatie Laravel Multitenancy 4.2 / shared-database tenants.**

1. Install and configure Spatie Laravel Multitenancy v4.
2. Add tenants and user membership.
3. Seed `personal` and `clinic` tenants.
4. Associate existing data with `personal`.
5. Add tenant resolution and membership validation.
6. Scope financial models and queued jobs.
7. Add isolation tests proving that one tenant cannot access another tenant's
   data.

This phase should use its own task and branch.

## Phase 3: Transactions, imports, and reconciliation

**Status: Complete — clean account-ID schema, idempotent CSV imports, matching,
splits, and reconciliation.**

1. Replace account-number primary keys with internal account IDs while
   preserving bank identifiers safely.
2. Introduce pending and posted transaction states and transaction origin.
3. Preserve raw import information and improve duplicate detection.
4. Match manual pending transactions to imported CSV rows.
5. Support category splits without changing the bank amount.
6. Add balance checkpoints and simple reconciliation.
7. Recalculate reconciliation validity after balance-affecting changes.
8. Add focused API and domain tests.

This phase can be one task initially and split only if account migration or CSV
matching becomes independently large.

## Phase 4: Clean frontend foundation

1. Create a clean App Router application in `mg5-fe` using the licensed Minimals
   `simple-next-ts` 5.0 source.
2. Remove demo pages, unused providers, mocks, and unnecessary dependencies.
3. Implement JWT authentication and one HTTP client.
4. Add the Personal / Clinic selector.
5. Add the dashboard shell and simplified navigation.
6. Preserve redirects from required legacy URLs.

Frontend work belongs in a separate task rooted in the `mg5-fe` repository.

## Phase 5: Core frontend workflows

Implement in this order:

1. CSV import status and history.
2. Transaction list, filters, editing, and uncategorized review.
3. Pending-to-import matching.
4. Account balance reconciliation.
5. Categories and automatic rules.
6. Accounts.
7. Optional management adjustments.

## Phase 6: Management dashboard

Start with a small dashboard:

- Current cash balance by account.
- Reconciled-through date and accounts needing review.
- Monthly revenue and operating expenses.
- Loan payments and interest.
- Estimated tax reserve.
- Free cash flow.
- Pending and uncategorized transaction counts.

Budgeting, investment scenarios, debt schedules, and additional clinic metrics
are deferred until the core data is reliable and the monthly workflow is being
used consistently.

## Deferred decisions

- Detailed accountant or GIFI mappings.
- Full loan and capital-asset modules.
- Formal accrual or double-entry accounting.
- Multiple clinic locations, departments, and advanced roles.
- Automated bank feeds in place of CSV import.
