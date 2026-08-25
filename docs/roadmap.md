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

**Status: Complete.**

1. Create a clean App Router application in `mg5-fe` using the licensed Minimals
   `simple-next-ts` 5.0 source.
2. Remove demo pages, unused providers, mocks, and unnecessary dependencies.
3. Implement JWT authentication and one HTTP client.
4. Add the Personal / Clinic selector.
5. Add the dashboard shell and simplified navigation.
6. Preserve redirects from required legacy URLs.

Frontend work belongs in a separate task rooted in the `mg5-fe` repository.

## Phase 5: Core frontend workflows

**Status: Phase 5A.1 through 5E.2 complete, including the backend APIs and the
CSV import, transaction, match-review, reconciliation, category, and automatic
rule frontend workflows.**

Implement in this order:

1. CSV import status and history.
2. Transaction list, filters, editing, and uncategorized review.
3. Pending-to-import matching.
4. Account balance reconciliation.
5. Categories and automatic rules.
6. Accounts.
7. Optional management adjustments.

Phase 5B.1 stabilizes the paginated, tenant-aware transaction API and its
category selector contract. Phase 5B.2 consumes that contract for transaction
listing, filters, editing, and uncategorized review. Phase 5C.1 adds the
tenant-aware grouped match-review API, and Phase 5C.2 implements its frontend.
Phase 5D.1 stabilizes preview, paginated history, store/correction, latest-valid,
and automatic recalculation for reconciliation; Phase 5D.2 implements its
frontend. Phase 5E.1 adds canonical management category kinds, safe three-level
hierarchy administration, distinct Personal and Clinic plans, and deterministic
literal categorization rules; Phase 5E.2 implements their frontend.

## Phase 6: Management dashboard

**Status: Phase 6.1 backend and Phase 6.2 frontend complete.**

Phase 6.1 deliberately reports only confirmed balances, reconciliation state,
currency-specific signed posted movement by canonical category type and
top-level management category, currency-specific confirmed net movement, and
current workflow counts. It never combines or converts currencies. Accounting
profit, tax liability, formal free cash flow, debt schedules, and semantic
inference from mutable category names remain deferred until durable reporting
mappings exist.

Delivered in Phases 6.1 and 6.2:

- Current confirmed balances by account and currency.
- Reconciliation status and accounts needing attention.
- Currency-specific posted monthly movement.
- Top-level management category groups.
- Pending and uncategorized workflow counts.

Deferred until real-data usage:

- Dedicated loan principal and interest reporting.
- Estimated tax reserve.
- Formal free cash flow.
- Budgets, forecasts, and investment scenarios.

## Phase 7: Operational safety and real-data pilot

**Current increment: Phase 7A — operational database safety and backup/restore
readiness.**

- Phase 7A provides documented, checksummed backups, guarded restoration, and a
  disposable restore drill.
- Phase 7B is the real-data pilot.
- Phase 7C contains only corrections discovered during the pilot.

Advanced management reporting remains deferred until real data has been used
for at least one or two monthly cycles.

## Deferred decisions

- Detailed accountant or GIFI mappings.
- Full loan and capital-asset modules.
- Formal accrual or double-entry accounting.
- Multiple clinic locations, departments, and advanced roles.
- Automated bank feeds in place of CSV import.
