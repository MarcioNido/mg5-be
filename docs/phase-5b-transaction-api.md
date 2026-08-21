# Phase 5B.1: Transaction API

## Outcome and scope

Phase 5B.1 provides the explicit tenant-aware HTTP contract required by the
Phase 5B.2 transaction frontend. It covers paginated transaction discovery,
safe transaction serialization, manual creation, enrichment and bank-field
editing, split replacement, and deletion. It also makes the existing category
index sufficient for hierarchical selectors.

This phase does not add reports, aggregates, bulk actions, matching review,
frontend reconciliation, category administration, categorization-rule
administration, attachments, or a second category API.

All routes require `auth:sanctum` and tenant selection. The frontend sends
`X-Tenant-Slug: personal` or `X-Tenant-Slug: clinic` through its same-origin
BFF.

## Transaction endpoints

- `GET /api/transactions`
- `POST /api/transactions`
- `GET /api/transactions/{transaction}`
- `PATCH|PUT /api/transactions/{transaction}`
- `DELETE /api/transactions/{transaction}`

Index, show, store, and update return the same transaction resource. Index is a
standard Laravel paginated resource with `data`, `links`, and `meta`. Store
returns `201`, successful show/update return `200`, and successful deletion
returns `204`.

## Listing parameters and ordering

| Parameter | Rules and meaning |
| --- | --- |
| `page` | Positive integer; standard Laravel page number |
| `per_page` | Integer from 1 through 50; default `25` |
| `account_id` | Account in the selected tenant |
| `status` | `pending` or `posted` |
| `origin` | `manual`, `csv`, or `system` |
| `category_id` | Category in the selected tenant; includes its descendants |
| `uncategorized` | Boolean; accepts query values such as `true`, `false`, `1`, and `0` |
| `date_from` | Inclusive Laravel-valid date |
| `date_to` | Inclusive Laravel-valid date, not before `date_from` when both are present |
| `search` | Optional text up to 200 characters, matched case-insensitively against description and notes |

`category_id` and `uncategorized=true` are mutually exclusive. Account and
category IDs from another tenant fail validation rather than producing an
apparently empty result. Pagination links preserve active query parameters.

Results are ordered by `transaction_date DESC, id DESC`. The ID tie-breaker
makes page navigation stable for transactions sharing a bank date.

The origin value is `csv`, matching the actual `TransactionOrigin` enum. The
API does not expose a synthetic `csv_import` alias.

## Categorization semantics

A transaction matches `category_id` when either its direct `category_id` or
one of its splits uses the selected category. Selecting a group includes that
category and descendants at any depth.

`uncategorized=true` means both of the following are true:

- the direct `category_id` is null;
- the transaction has no splits.

A transaction with no direct category but at least one split is therefore
categorized.

`GET /api/categories` returns the selected tenant's categories as a flat list.
Each item has `id`, `name`, `type`, `level`, and a nullable `parent` summary with
the same public identity fields. The frontend can construct a tree from the
parent ID without a second endpoint.

## Public transaction resource

```json
{
  "id": 91,
  "account_id": 17,
  "account": {
    "id": 17,
    "name": "Clinic Chequing",
    "type": "chequing",
    "currency": "CAD"
  },
  "transaction_date": "2026-08-20",
  "amount": "-125.4000",
  "description": "MEDICAL SUPPLIES",
  "notes": "Reviewed",
  "status": "posted",
  "origin": "csv",
  "posted_at": "2026-08-20T14:12:00.000000Z",
  "category_id": 8,
  "category": {
    "id": 8,
    "name": "Medical supplies",
    "type": "variable expenses",
    "level": 2,
    "parent": {
      "id": 3,
      "name": "Direct clinical costs",
      "type": "variable expenses",
      "level": 1
    }
  },
  "splits": [
    {
      "id": 14,
      "category_id": 8,
      "amount": "-125.4000",
      "description": "Consumables",
      "category": {
        "id": 8,
        "name": "Medical supplies",
        "type": "variable expenses",
        "level": 2,
        "parent": {
          "id": 3,
          "name": "Direct clinical costs",
          "type": "variable expenses",
          "level": 1
        }
      }
    }
  ],
  "is_import_linked": true,
  "bank_fields_editable": false,
  "deletable": false
}
```

`category` and `category.parent` may be null. `posted_at` is ISO 8601 or null.
Relations and import-link existence are eager loaded or calculated with
existence subqueries for the complete page; resources do not issue one query
per transaction.

The response never exposes tenant IDs, account numbers, internal timestamps,
import or movement IDs, fingerprints, bank references, file paths, or raw and
normalized import payloads. Splits are explicit resources rather than raw
Eloquent serialization.

## Decimal values

Transaction and split amounts remain `DECIMAL(19,4)` in persistence and are
decimal strings with exactly four fractional digits in JSON. Create and update
accept signed decimal input with at most four fractional digits. Domain sum and
equality operations continue using four-decimal integer units; financial
values are not converted to floats.

## Creation

`POST /api/transactions` requires `account_id`, `transaction_date`, `amount`,
and `description`. It accepts nullable `category_id` and `notes`, optional
`status`, and optional splits containing `category_id`, `amount`, and nullable
`description`.

The server always assigns `origin=manual`; sending `origin` is a validation
error. Status defaults to `pending`. Explicit `posted` remains supported by the
existing domain and sets `posted_at`. Account, direct category, and split
categories must belong to the selected tenant. A non-empty split list must sum
exactly to the signed transaction amount. Transaction creation and split
replacement run atomically.

## Editing and deletion

For a transaction with no import link, PATCH or PUT may update the currently
supported bank fields (`account_id`, `transaction_date`, `amount`, and
`status`) and enrichment fields (`category_id`, `description`, `notes`, and
`splits`). Relevant bank-field changes continue to invoke the existing
reconciliation recalculation lifecycle.

A transaction is import-linked if either `import_rows` or
`imported_movements` references it. For such a transaction:

- account, bank date, amount, and status are read-only;
- category, description, notes, and valid splits remain editable;
- resending a protected field with its current semantic value is idempotent;
- changing a protected field returns `422` with a public validation message;
- direct deletion returns `422`.

An unlinked manual transaction is deletable under the current domain rules.
Deletion remains soft deletion and triggers reconciliation recalculation when
applicable.

## Capability flags

- `is_import_linked`: true for a link through either import relation.
- `bank_fields_editable`: false exactly when import-linked.
- `deletable`: false exactly when import-linked under the current rules.

The Phase 5B.2 UI should use these flags for controls and guidance, while still
handling server validation because capabilities can change between reads and
writes.

## Tenancy and errors

Financial queries retain the current-tenant global scope. Membership is
checked before route model binding. Consequently, a transaction belonging to
the other tenant cannot be shown, updated, or deleted and resolves as `404`.
Split relations are constrained by both transaction and tenant at the database
level.

Relevant HTTP errors are:

- `401`: missing or invalid Sanctum authentication;
- `403`: authenticated user is not a member of the selected tenant;
- `404`: unknown tenant slug or an absent/cross-tenant route-bound resource;
- `422`: invalid filters or payload, cross-tenant relation ID, split mismatch,
  conflicting category filters, protected imported bank-field change, or
  imported transaction deletion;
- `503`: application maintenance/unavailability response; the transaction
  domain does not otherwise introduce an asynchronous dependency in this API.

Validation responses use Laravel's standard `message` and `errors` object.

## Phase 5B.2 frontend handoff

The transaction frontend should:

1. send the active `X-Tenant-Slug` on every BFF request;
2. consume `data`, `links`, and `meta`, using the server's preserved links or
   retaining the same filter state when changing pages;
3. use the documented flat query parameters, not the removed legacy
   `filter[...]` and `orderBy[...]` convention;
4. send origin filter value `csv` for imported transactions;
5. treat dates as date-only `YYYY-MM-DD` values and amounts as strings;
6. build account labels only from the public account summary and category trees
   from `level` plus `parent.id`;
7. display a transaction with splits as categorized even when its direct
   `category_id` is null;
8. use PATCH for edits and send only edited fields where practical;
9. use the capability flags to disable bank fields and deletion for linked
   imports, and surface any `422` race-condition response;
10. stop matching work at navigation/display boundaries because confirm/reject
    remains in Phase 5C.

## Deferred work

Advanced filters, totals, dashboards, reports, bulk selection, attachments,
matching confirmation/rejection UI, reconciliation UI, category CRUD,
categorization-rule administration, and management adjustments remain outside
Phase 5B.1.
