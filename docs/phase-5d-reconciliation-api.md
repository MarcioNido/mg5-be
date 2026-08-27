# Phase 5D.1: Bank reconciliation API

## Outcome and scope

Phase 5D.1 provides the small tenant-aware bank balance confirmation API needed
by the Phase 5D.2 frontend. It is a management workflow for checking that MG5's
posted cash agrees with a balance observed at the bank. It is not an accounting
period close, statement-line clearing workflow, or approval system.

All endpoints require Sanctum authentication, tenant selection, and membership
in the selected tenant. The frontend sends `X-Tenant-Slug: personal` or
`X-Tenant-Slug: clinic` through its same-origin BFF. Account route binding is
tenant-scoped, so an account from another tenant returns `404`.

## Balance and opening checkpoint

`ReconciliationService` is the single calculation authority. For a selected
account and statement date, it calculates:

```text
account opening balance
+ posted transactions after opening_balance_date
+ posted transactions through statement_date inclusively
= calculated balance
```

A transaction on `opening_balance_date` is excluded because the opening balance
is the checkpoint at that date. When no opening date is present, every posted
transaction through the statement date is included. Pending transactions,
soft-deleted transactions, and transactions after the statement date are
excluded. Category, description, notes, and splits do not participate.

## Endpoints

### Preview

`GET /api/accounts/{account}/reconciliations/preview?statement_date=YYYY-MM-DD`

`statement_date` is required and must be a strict valid `YYYY-MM-DD` civil date.

The preview also returns `review_period`. Its `date_from` is the day after the
latest prior valid reconciliation, falling back to the day after the account's
opening balance date. It is `null` when neither checkpoint exists. `date_to`
always matches the requested statement date. This range can be passed directly
to the transaction list without persisting review state.
Preview is read-only and creates no history row.

```json
{
  "data": {
    "statement_date": "2026-08-31",
    "calculated_balance": "1250.4000",
    "review_period": {
      "date_from": "2026-08-01",
      "date_to": "2026-08-31",
      "previous_statement_date": "2026-07-31"
    }
  }
}
```

### History

`GET /api/accounts/{account}/reconciliations`

History accepts a positive integer `page` and `per_page` from 1 through 50,
defaulting to 15. It returns standard Laravel `data`, `links`, and `meta`, and
pagination links preserve the query string. Rows are ordered by statement date
descending and ID descending.

Each `data` item contains only:

```json
{
  "id": 42,
  "statement_date": "2026-08-31",
  "entered_bank_balance": "1240.4000",
  "calculated_balance": "1250.4000",
  "difference": "-10.0000",
  "is_valid": false,
  "reconciled_at": null
}
```

`difference` always means `entered_bank_balance - calculated_balance`. A
positive value means the entered bank balance is higher than MG5's calculated
balance; a negative value means it is lower.

### Store or correct a statement date

`POST /api/accounts/{account}/reconciliations`

The request requires:

```json
{
  "statement_date": "2026-08-31",
  "entered_bank_balance": "1250.4"
}
```

The date is a strict valid `YYYY-MM-DD` civil date. The balance must be a JSON
string, not a number, with zero through four fractional digits and no more than
15 integer digits. It is normalized through `Money` to four decimal places.

MG5 calculates the current balance and creates the account/date row when it
does not exist (`201`). Resubmitting the same account and date corrects and
replaces that date's entered bank balance and calculated result (`200`); it
does not add another history row. The database's existing tenant/account/date
unique constraint provides the final concurrency guard.

The response is the same explicit reconciliation resource used by history.
`is_valid` is true exactly when the entered and calculated balances have equal
four-decimal integer units. `reconciled_at` is populated only while they agree
and is null while they differ.

### Latest valid

`GET /api/accounts/{account}/reconciliations/latest`

The response contains the same reconciliation resource for the most recent
currently valid statement date, or `{"data": null}` when none is valid. A
newer invalid row does not hide an older valid row. This latest valid date is
the account's reconciled-through date.

## Automatic recalculation

The existing model-level reconciliation lifecycle remains authoritative for
changes made through HTTP, services, imports, or other application code. A
posted transaction insertion, deletion, ignore/restore action, or a change to
its account, bank date, amount, or posted/pending state, recalculates every
affected statement date. Moving an included transaction between accounts recalculates both the old
and new accounts. A transaction strictly after a statement date cannot change
that row.

Changing an account's opening balance or opening date recalculates all of its
rows. Recalculation can invalidate a row by clearing `reconciled_at`, or restore
it when exact equality returns. Consequently, latest-valid automatically moves
backward or becomes null after retroactive changes and returns when equality is
restored. No invalidation reason, actor, or separate audit record is stored.

## Exact values, dates, and tenancy

All public monetary values are exact strings with four fractional digits.
Calculations, equality, and difference use `Money` integer units and never
floating-point subtraction. Statement and transaction dates are civil dates;
business-date interpretation uses the configured `America/Toronto` timezone,
while `reconciled_at` is an ISO timestamp using the application's existing UTC
storage strategy.

Financial model queries fail closed when no tenant is current. Global scopes,
membership middleware, route binding, and composite database relationships keep
Personal and Clinic data isolated. Resources deliberately omit tenant IDs,
nested account IDs, raw model attributes, and internal timestamps.

## Phase 5D.2 frontend handoff

The frontend should select an account, request preview for the chosen civil
date, accept the actual balance as a decimal string, and submit it to the store
endpoint. It should use `difference` as returned rather than recalculate with
JavaScript numbers, show validity and the latest reconciled-through date, and
consume history pagination metadata. Correcting a date should replace its row
in the UI.

## Limitations

This phase intentionally has no accounting periods, per-transaction cleared
flags, statement-line ticking, approvals, audit actors, invalidation reasons,
undo history, attachments, or bank feeds. The later reversible duplicate-ignore
marker is financial exclusion, not a persisted statement checkbox or period
close.
