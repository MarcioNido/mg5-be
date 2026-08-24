# Phase 5C.1: Match review API

## Outcome and scope

Phase 5C.1 provides the explicit tenant-aware API contract required by the
Phase 5C.2 matching review frontend. It replaces the legacy list of raw
`MatchSuggestion` models with paginated review cases while preserving the
existing public routes and matching domain rules.

This phase does not change candidate discovery, the three-day date window, the
`0.75` confidence threshold, or confidence calculation. It does not add a
frontend, approximate-value matching, manual suggestion creation, undo, bulk
actions, reconciliation, or category/rule administration.

All endpoints require `auth:sanctum`, tenant selection, and membership in the
selected tenant. The frontend sends `X-Tenant-Slug: personal` or
`X-Tenant-Slug: clinic` through its same-origin BFF.

## Why reviews are grouped by import row

The user's decision is about one imported bank movement and its possible
manual equivalents. The public review ID is therefore the `import_rows.id`,
and one list item contains the imported transaction plus every actionable
candidate. Pagination counts review cases, not individual suggestions. This
prevents one ambiguous bank line from occupying multiple unrelated list rows.

An actionable case has a `needs_review` import row, an existing imported
transaction, an account in the current tenant, and at least one pending
suggestion whose candidate transaction is still pending. Cases with stale or
missing candidate transactions are excluded from the actionable list; action
requests still revalidate state under lock and return `422`.

## Endpoints

- `GET /api/match-suggestions`
- `POST /api/match-suggestions/{suggestion}/confirm`
- `POST /api/match-suggestions/{suggestion}/reject`

The legacy route name remains for compatibility. Its list contract now
represents review cases rather than serialized suggestion rows.

## Pagination, filtering, and ordering

`GET /api/match-suggestions` accepts:

| Parameter | Rules |
| --- | --- |
| `page` | Positive integer |
| `per_page` | Integer from 1 through 50; default `15` |
| `account_id` | Optional account belonging to the selected tenant |

Laravel pagination returns `data`, `links`, and `meta`. Pagination links
preserve active query parameters. A cross-tenant `account_id` returns `422`.
An empty state is a successful page with `data: []`.

Cases are ordered by imported `transaction_date DESC`, then review/import-row
ID descending. Candidates are ordered by persisted confidence descending,
absolute date difference ascending, then suggestion ID ascending. Confidence
is never recalculated by the controller or Resources.

## Public review contract

```json
{
  "id": 81,
  "account": {
    "id": 17,
    "name": "Clinic Chequing",
    "type": "chequing",
    "currency": "CAD"
  },
  "import": {
    "id": 24,
    "original_filename": "rbc-august.csv",
    "source_name": "RBC",
    "created_at": "2026-08-24T14:12:00.000000Z"
  },
  "line_number": 14,
  "imported_transaction": {
    "id": 91,
    "transaction_date": "2026-08-20",
    "amount": "-125.4000",
    "description": "MEDICAL SUPPLIES",
    "notes": null,
    "status": "posted",
    "origin": "csv",
    "category": null,
    "splits": []
  },
  "candidates": [
    {
      "suggestion_id": 33,
      "confidence": "0.9250",
      "transaction": {
        "id": 72,
        "transaction_date": "2026-08-19",
        "amount": "-125.4000",
        "description": "Equipment supplies",
        "notes": "Keep receipt",
        "status": "pending",
        "origin": "manual",
        "category": null,
        "splits": []
      }
    }
  ]
}
```

`original_filename` remains `null` for older imports; Phase 5C.2 may display
`Imported CSV`. Category objects contain only `id`, `name`, `type`, `level`,
and the public parent summary when loaded. Split objects contain only `id`,
`category_id`, four-decimal `amount`, `description`, and public `category`.

Transaction and split amounts, and candidate confidence, are JSON strings with
exactly four fractional digits. Financial values are not converted to floats.

## Imported transaction versus candidate

`imported_transaction` is the temporary posted transaction created from the
bank row so confirmed cash remains complete during review. Each candidate is
an independent manual pending transaction that may represent that movement.
There is never a second definitive posted transaction for the same bank
movement.

Confidence is only a review aid. It is the persisted result of the existing
matching algorithm and must not be interpreted by the frontend as an automatic
decision.

## Confirmation

Confirm delegates to `TransactionMatchingService` and returns:

```json
{
  "data": {
    "review_id": 81,
    "suggestion_id": 33,
    "resolution": "matched",
    "transaction": {}
  }
}
```

`transaction` uses the complete Phase 5B.1 `TransactionResource` contract,
including account, category, splits, and capability flags. On success:

- the selected suggestion becomes `confirmed` and pending siblings become
  `rejected`;
- the import row becomes `matched`;
- the imported movement and import row point to the promoted manual
  transaction;
- the temporary imported transaction is soft deleted;
- the selected manual transaction becomes `posted` using the bank account,
  date, amount, status, and posting time;
- manual description, notes, category, and splits remain intact;
- the result has `is_import_linked=true`, `bank_fields_editable=false`, and
  `deletable=false`;
- unselected candidates remain independent pending transactions; and
- the entire review case leaves the list.

## Rejection

Rejecting one of several candidates removes only that possibility. The import
row stays `needs_review` and the response is:

```json
{
  "data": {
    "review_id": 81,
    "suggestion_id": 33,
    "resolution": "candidate_rejected",
    "remaining_candidates": 1
  }
}
```

Rejecting the last candidate keeps the temporary imported transaction as the
definitive posted bank movement, changes the import row to `imported`, and
removes the case:

```json
{
  "data": {
    "review_id": 81,
    "suggestion_id": 34,
    "resolution": "imported_transaction_kept",
    "remaining_candidates": 0
  }
}
```

## Concurrency and stale responses

Confirm and reject preserve the service's database transactions, row locks,
and state revalidation. They are deliberately not made idempotent. A stale
action returns Laravel's public `422` validation response with an error under
`suggestion` when the suggestion, candidate, import row, imported transaction,
or imported-movement link is no longer actionable. This includes a case
resolved by a competing candidate. Phase 5C.2 must reload the list on `422`.

## Tenant isolation and deliberately private fields

Global tenant scopes remain active for review rows, suggestions, accounts,
imports, transactions, categories, and splits. Membership is checked before
route model binding, so a cross-tenant suggestion resolves as `404`. Personal
and Clinic list responses cannot include each other's cases or relations. With
no current tenant, financial model queries continue to fail closed.

The API does not expose tenant IDs, account numbers, internal stored filenames
or paths, raw/normalized payloads, fingerprints, occurrence numbers, imported
movement IDs, bank references, tokens, internal timestamps, or raw Eloquent
models.

## Phase 5C.2 frontend handoff

Phase 5C.2 should:

1. render one card per review case;
2. compare `imported_transaction` with every candidate and emphasize date,
   description, notes, category, and splits;
3. use confidence only as supporting information;
4. confirm exactly one candidate or reject candidates individually;
5. request user confirmation before either action;
6. update or remove the card according to `resolution` and
   `remaining_candidates`;
7. treat `422` as stale state and reload;
8. avoid editing transactions inside matching review; and
9. leave bank reconciliation for its subsequent phase.

## Limitations

There is no undo, bulk action, manual candidate creation, approximate amount
matching, edit-in-review workflow, reconciliation UI, category administration,
or rule administration. The API intentionally exposes only currently
actionable cases and does not repair inconsistent historical matching state.
