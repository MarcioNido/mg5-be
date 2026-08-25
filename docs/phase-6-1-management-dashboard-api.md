# Phase 6.1: Management dashboard summary API

## Outcome and scope

Phase 6.1 adds one read-only, tenant-aware summary for the first management
dashboard. It reports confirmed bank movement and current workflow state that
MG5 can determine from durable account, transaction, category, split, and
reconciliation data. It does not present those values as accounting profit,
taxable income, tax liability, formal free cash flow, or a debt schedule.

The endpoint requires Sanctum authentication, tenant selection, and membership:

```text
GET /api/dashboard/summary?month=YYYY-MM
```

`month` is optional. It must be an exact, possible `YYYY-MM` civil month. When
omitted, it defaults to the current month in `config('app.business_timezone')`
(`America/Toronto`). Historical months are supported. Months after the current
Toronto civil month return `422` under `month`; the endpoint is a confirmed
activity report, not a forecast. Values such as `2026-2`, `2026-00`, complete
dates, and timestamps are not normalized.

## Response contract

The response is one explicit resource:

```json
{
  "data": {
    "period": {
      "month": "2026-08",
      "start_date": "2026-08-01",
      "end_date": "2026-08-31"
    },
    "as_of_date": "2026-08-25",
    "accounts": [
      {
        "id": 17,
        "name": "Clinic Chequing",
        "type": "chequing",
        "currency": "CAD",
        "current_balance": "1250.4000",
        "last_posted_transaction_date": "2026-08-24",
        "reconciliation": {
          "status": "activity_after_reconciliation",
          "needs_attention": true,
          "latest_valid": {
            "statement_date": "2026-07-31",
            "reconciled_at": "2026-08-01T14:00:00.000000Z"
          },
          "latest_attempt": {
            "statement_date": "2026-07-31",
            "is_valid": true
          }
        }
      }
    ],
    "account_totals_by_currency": [
      { "currency": "CAD", "amount": "1250.4000" }
    ],
    "period_activity": {
      "posted_transactions_count": 24,
      "by_currency": [
        {
          "currency": "CAD",
          "posted_transactions_count": 20,
          "amounts_by_type": {
            "income": "10000.0000",
            "expense": "-7250.0000",
            "transfer": "-500.0000"
          },
          "uncategorized_amount": "-25.0000",
          "confirmed_net_change": "2225.0000",
          "groups": [
            {
              "category": {
                "id": 3,
                "name": "Direct clinical costs",
                "type": "expense",
                "level": 1
              },
              "amounts_by_type": {
                "income": "10000.0000",
                "expense": "-7250.0000",
                "transfer": "-500.0000"
              },
              "net_change": "2250.0000"
            }
          ]
        },
        {
          "currency": "USD",
          "posted_transactions_count": 4,
          "amounts_by_type": {
            "income": "0.0000",
            "expense": "0.0000",
            "transfer": "0.0000"
          },
          "uncategorized_amount": "650.0000",
          "confirmed_net_change": "650.0000",
          "groups": []
        }
      ]
    },
    "workflow": {
      "pending_transactions_count": 3,
      "uncategorized_posted_count": 2,
      "uncategorized_pending_count": 1,
      "accounts_needing_attention_count": 1
    }
  }
}
```

`latest_valid`, `latest_attempt`, and `last_posted_transaction_date` are null
when absent. `latest_attempt.is_valid` and `needs_attention` are JSON booleans.
The resource never exposes tenant IDs, account numbers, deletion metadata, raw
models, queue state, or SQL/category implementation details.

Every monetary value is an exact signed decimal string with four fractional
digits. Aggregate rows are converted to four-decimal integer units before PHP
combination; PHP floating-point arithmetic is not used.

## Current balances and currencies

`as_of_date` is today in Toronto. An account balance always means:

```text
opening balance
+ posted transactions after opening_balance_date, when present
+ posted transactions through as_of_date inclusively
```

A posted transaction on `opening_balance_date` is excluded because the opening
balance is the checkpoint for that date. Pending, future-dated, and
soft-deleted transactions are excluded. This matches `ReconciliationService`.
The selected reporting month does not change current account balances.

Accounts are ordered case-insensitively by name and then ID. Currency totals
are ordered by currency code. Balances are combined only within the same
currency; MG5 does not convert or silently combine CAD, USD, or another
currency.

## Reconciliation state

Reconciliation validity is evaluated from exact equality between the entered
bank balance and the currently calculated balance. A timestamp alone never
establishes validity. Status precedence is:

1. `never_reconciled`: no attempt exists.
2. `latest_attempt_invalid`: the most recent attempt is currently invalid. A
   newer invalid attempt therefore takes precedence over an older valid state.
3. `activity_after_reconciliation`: the latest attempt is valid, but a posted
   transaction exists after the latest valid statement date.
4. `up_to_date`: a valid latest state exists, no later posted activity exists,
   and no newer invalid attempt supersedes it.

Only `up_to_date` has `needs_attention=false`. The workflow attention count is
derived from the returned accounts and therefore always equals the number of
account items whose `needs_attention` is true. Automatic reconciliation
recalculation is respected: a row whose balances no longer agree is not
reported as valid even if it was valid in the past.

## Selected-period activity

The period dates describe the complete selected civil month. Period activity
includes posted, active transactions whose `transaction_date` lies within
those inclusive boundaries. Pending and soft-deleted transactions are
excluded. For the current month, this remains a complete-month date filter;
`as_of_date` limits current balances, not the selected-period contract.

The top-level `posted_transactions_count` counts each included bank transaction
once and is currency-independent. `by_currency` is ordered by the persisted
currency code of each transaction's active account. Its bucket counts sum to
the top-level count. A period with no posted activity returns a count of zero
and `by_currency: []`; it does not manufacture a bucket from tenant or
application defaults.

Every monetary subtotal exists only inside a currency bucket. MG5 never adds
CAD, USD, or any other currencies together and performs no currency conversion.
Within each bucket, `confirmed_net_change` is the signed sum of each included
transaction amount once, including uncategorized transactions. Expense refunds
and other reversed movements keep their bank sign.

A transaction without splits allocates its complete amount to its direct
category. A transaction with splits allocates only its split amounts; an
unexpected direct category on the same transaction is ignored for allocation
and cannot double count the bank movement. A transaction with neither a direct
category nor splits contributes its complete amount to `uncategorized_amount`
and `confirmed_net_change`, but not to category-type or group totals.

Canonical `income`, `expense`, and `transfer` totals are calculated separately
per currency and use the type of the category actually assigned to each direct
amount or split. A child type is independent of its parent type. Each allocation
also rolls up to the assigned category's top-level ancestor within that same
currency. The same management category can therefore appear independently in
multiple currency buckets. Because descendants may have different types, every
root group contains all three type subtotals and a signed `net_change`. Groups
with no activity are omitted and active groups are ordered by root type,
case-insensitive root name, and root ID.

No group or movement meaning is inferred from mutable English category names.
When split allocations are valid, group net changes plus
`uncategorized_amount` equal `confirmed_net_change` in exact integer units
independently in every currency bucket.

The future frontend must render one currency at a time or use separate
sections/cards for each bucket. A tenant-wide record count may be shown, but no
currency-specific monetary subtotal may be presented as a combined amount.

## Current workflow counts

Workflow counts are current, active, tenant-wide values and do not use the
selected month:

- `pending_transactions_count`: every pending transaction;
- `uncategorized_posted_count`: posted transactions with no direct category
  and no splits;
- `uncategorized_pending_count`: pending transactions with no direct category
  and no splits;
- `accounts_needing_attention_count`: derived from account reconciliation
  states.

A transaction with splits is categorized even when `category_id` is null.
Soft-deleted transactions are excluded.

## Tenant isolation and query strategy

The route uses the existing `auth:sanctum` and `tenant` middleware. Membership
is checked before the summary runs, and all financial Eloquent queries retain
their fail-closed current-tenant scope. Personal and Clinic accounts,
categories, transactions, splits, reconciliations, balances, and totals never
cross contexts.

The service uses a fixed set of account, reconciliation, category, aggregate,
and workflow queries. Balance and period totals are grouped by account currency
in SQL and returned as integer monetary units. Only the small active account
list, active category hierarchy, latest reconciliation rows, and grouped
aggregate rows are loaded.
It does not call `ReconciliationService` per account and does not load complete
transaction history. The feature regression test verifies that query count is
unchanged when accounts, reconciliations, categories, direct transactions, and
splits are added.

## Tests

The focused Phase 6.1 feature suite covers authentication, membership, tenant
selection, strict month handling, Toronto defaults, the complete empty and
populated contracts, safe serialization, exact strings, opening checkpoints,
as-of balance rules, currency isolation, deterministic ordering, every public
reconciliation status, retroactive invalidation, direct and split allocations,
independent child types, three-level rollup, signed refunds, full-month
boundaries, workflow counts, soft deletion, tenant isolation, fail-closed
models, and bounded query count.

## Deliberate limitations

This phase does not calculate or claim accounting profit, taxable income, tax
liability, tax filing, formal free cash flow, loan amortization, principal or
interest inferred from names, exchange-rate conversion, budgets, forecasts,
prior-period comparisons, charts, frontend work, caching, exports,
accountant/GIFI mappings, adjustments, or dashboard polling.
