# Phase 5A.1: Accounts and CSV import API

## Outcome

Phase 5A.1 prepares the tenant-aware HTTP contract used by the Phase 5A.2
frontend. It covers basic account management, one-file CSV upload, paginated
import history, polling, and a deliberately limited import result. It does not
change Phase 3 financial, matching, movement deduplication, occurrence, or
reconciliation rules.

All endpoints require Sanctum authentication and the tenant middleware. The
frontend sends `X-Tenant-Slug: personal` or `X-Tenant-Slug: clinic`; `X-Tenant`
remains a compatibility alias. The authenticated user must belong to the
selected tenant. IDs are resolved inside that tenant, so a resource from the
other tenant returns `404` and an unauthorized tenant selection returns `403`.

## Accounts

### Endpoints

- `GET /api/accounts`
- `POST /api/accounts`
- `GET /api/accounts/{account}`
- `PUT|PATCH /api/accounts/{account}`
- `DELETE /api/accounts/{account}`

Account requests use these fields:

| Field | Rules |
| --- | --- |
| `account_number` | Optional, nullable string, unique inside the selected tenant only |
| `name` | Required on create, string, maximum 255 characters |
| `type` | `chequing`, `savings`, `credit`, `investment`, `cash`, `other`, or `debit` |
| `currency` | Optional three-character code; database default is `CAD` |
| `opening_balance` | Optional exact decimal with up to four fractional digits; default `0.0000` |
| `opening_balance_date` | Optional nullable date |

The same account number may exist once in Personal and once in Clinic. Laravel
validation failures use HTTP `422` and the standard `message` plus `errors`
object.

Example account resource:

```json
{
  "data": {
    "id": 17,
    "account_number": "06402-5031752",
    "name": "RBC Chequing",
    "type": "chequing",
    "currency": "CAD",
    "opening_balance": "1250.0000",
    "opening_balance_date": "2026-01-01"
  }
}
```

The accounts index does not load or return transactions. Current balance and
reconciliation data are also intentionally absent.

## CSV upload

### `POST /api/files`

Send `multipart/form-data` with:

- `account_id`: an account in the selected tenant;
- `file`: one `.csv` or `.txt` file, maximum 10 MB.

The content must match an existing RBC or Triangle statement export. An empty
file or an unrecognized CSV returns HTTP `422`, associated with `file`:

```json
{
  "message": "Unsupported CSV format. Upload an RBC or Triangle statement export.",
  "errors": {
    "file": [
      "Unsupported CSV format. Upload an RBC or Triangle statement export."
    ]
  }
}
```

The original browser filename is truncated to the database-supported 255
characters and stored only as display metadata. Laravel continues to choose the
physical storage name. The internal path is never returned. If format
recognition fails after storage, the newly stored file is deleted.

A new upload returns `201`; an idempotent duplicate returns `200`. Both use the
same body shape. With `QUEUE_CONNECTION=sync`, the returned resource reflects
the final processing status. With an asynchronous queue it normally starts as
`pending`.

New upload:

```json
{
  "data": {
    "id": 123,
    "account_id": 17,
    "account": {
      "id": 17,
      "name": "RBC Chequing",
      "type": "chequing",
      "currency": "CAD"
    },
    "original_filename": "rbc-august.csv",
    "source_name": "RBC",
    "source_type": "csv",
    "status": "pending",
    "total_rows": 0,
    "processed_rows": 0,
    "failed_rows": 0,
    "error_message": null,
    "created_at": "2026-08-20T16:20:00.000000Z",
    "updated_at": "2026-08-20T16:20:00.000000Z"
  },
  "meta": {
    "duplicate_upload": false
  }
}
```

Duplicate upload:

```json
{
  "data": {
    "id": 123,
    "account_id": 17,
    "account": {
      "id": 17,
      "name": "RBC Chequing",
      "type": "chequing",
      "currency": "CAD"
    },
    "original_filename": "rbc-august.csv",
    "source_name": "RBC",
    "source_type": "csv",
    "status": "complete",
    "total_rows": 42,
    "processed_rows": 42,
    "failed_rows": 0,
    "error_message": null,
    "created_at": "2026-08-20T16:20:00.000000Z",
    "updated_at": "2026-08-20T16:20:02.000000Z"
  },
  "meta": {
    "duplicate_upload": true
  }
}
```

Exact file identity remains scoped by tenant and selected account. Reuploading
the same bytes for the same account returns the existing import and deletes the
new physical copy. The same bytes for another account or tenant create a new
import. Duplicate detection never relies on the display filename.

## Import history

### `GET /api/files`

Results are newest first and use Laravel pagination. Query parameters:

| Parameter | Rules |
| --- | --- |
| `per_page` | Optional integer from 1 to 50; default 15 |
| `account_id` | Optional account ID belonging to the selected tenant |
| `status` | Optional import status enum |

Example:

```json
{
  "data": [
    {
      "id": 123,
      "account_id": 17,
      "account": {
        "id": 17,
        "name": "RBC Chequing",
        "type": "chequing",
        "currency": "CAD"
      },
      "original_filename": "rbc-august.csv",
      "source_name": "RBC",
      "source_type": "csv",
      "status": "complete",
      "total_rows": 42,
      "processed_rows": 42,
      "failed_rows": 0,
      "error_message": null,
      "created_at": "2026-08-20T16:20:00.000000Z",
      "updated_at": "2026-08-20T16:20:02.000000Z"
    }
  ],
  "links": {
    "first": "http://localhost/api/files?page=1",
    "last": "http://localhost/api/files?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "links": [],
    "path": "http://localhost/api/files",
    "per_page": 15,
    "to": 1,
    "total": 1
  }
}
```

The account relation is eager loaded to avoid N+1 queries.

## Import detail and polling

### `GET /api/files/{file}`

The detail returns the same import summary and account summary, plus rows sorted
by `line_number`:

```json
{
  "data": {
    "id": 123,
    "account_id": 17,
    "account": {
      "id": 17,
      "name": "RBC Chequing",
      "type": "chequing",
      "currency": "CAD"
    },
    "original_filename": "rbc-august.csv",
    "source_name": "RBC",
    "source_type": "csv",
    "status": "complete_with_errors",
    "total_rows": 42,
    "processed_rows": 41,
    "failed_rows": 1,
    "error_message": null,
    "created_at": "2026-08-20T16:20:00.000000Z",
    "updated_at": "2026-08-20T16:20:02.000000Z",
    "rows": [
      {
        "id": 501,
        "line_number": 2,
        "status": "imported",
        "transaction_id": 991,
        "error_message": null,
        "transaction_date": "2026-08-18",
        "description": "UTILITY BILL PMT",
        "amount": "-158.1700"
      }
    ]
  }
}
```

The frontend polls this endpoint while status is `pending` or `processing`, and
stops for a terminal status:

- `complete`: every row completed without a row error;
- `complete_with_errors`: processing finished with one or more failed rows;
- `failed`: a global processing failure; inspect `error_message`.

`processed_rows` counts successfully processed rows and `failed_rows` counts
row-level failures. Their sum equals `total_rows` for completed imports. A
global failure preserves the counters reached before the failure.

## Supported sources

- RBC CSV exports with the established account transaction header.
- Triangle CSV or TXT statement exports beginning with `MY ACCOUNT TRANSACTIONS`.

No new readers, OFX/QFX, multiple upload, cloud storage, or realtime transport
is introduced in this phase.

## Deliberately private fields

Import responses never expose the physical storage path, tenant ID, file or
movement fingerprints, occurrence numbers, imported movement IDs, bank account
numbers from statement rows, bank references, raw/normalized payloads, queue
internals, match suggestions, or raw Eloquent models. Import-row amounts are
decimal strings; financial values are never converted to floats. Timestamps are
ISO 8601.

Older imports may have `original_filename: null`. The Phase 5A.2 frontend should
display `Imported CSV` in that case.

## Phase 5A.2 frontend handoff

The frontend remains pending. It needs to implement:

1. tenant and account selection before upload;
2. `.csv`/`.txt`, single-file, and 10 MB client-side hints while still showing
   server validation errors;
3. explicit duplicate messaging from `meta.duplicate_upload`;
4. paginated history with optional `account_id` and `status` filters;
5. polling only for `pending` and `processing` and stopping at terminal states;
6. `Imported CSV` fallback for a null original filename;
7. safe row-result display without matching review controls (deferred to Phase 5C).
