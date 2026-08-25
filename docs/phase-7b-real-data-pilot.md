# Phase 7B real-data pilot

## Status and boundaries

Phase 7A is complete: checksummed backup, guarded restore, and disposable
restore-drill procedures are available. Phase 7B.1 establishes the secure clean
baseline: exactly the Personal and Clinic tenants, their complete recommended
category plans (39 active Personal categories and 53 active Clinic categories),
and no users or financial activity. Phase 7B.2 is the user-driven real-data
pilot.

Do not import a real statement during Phase 7B.1. Credentials must never be
shared in prompts, passed as command arguments, written in documentation or
configuration, or committed to Git.

## Secure administrator bootstrap

Database seeding deliberately creates no administrator. The operator must run
the bootstrap personally in an interactive terminal:

```sh
./vendor/bin/sail artisan mg5:bootstrap-admin
```

The command asks for a name and normalized email, then reads a password and its
confirmation through hidden input. Passwords must contain at least 12
characters with upper- and lowercase letters, a number, and a symbol. The
command hashes the password and atomically attaches the user to Personal and
Clinic. If the email already exists, it changes nothing unless the operator
explicitly confirms an interactive recovery update.

## Recommended onboarding order

1. Create the administrator with the secure interactive command.
2. Sign in and verify that both Personal and Clinic can be selected.
3. Create accounts, keeping every bank account and currency separate.
4. Establish an opening checkpoint for each account.
5. Import statements oldest to newest, one account at a time.
6. Review match suggestions and uncategorized transactions before continuing.
7. Create only conservative automatic rules whose literal matching behavior has
   been reviewed.
8. Reconcile each account to its statement balances.
9. Review the management dashboard, keeping currency totals separate.
10. Create and verify a new backup.

## Opening checkpoints

Choose the closing balance from immediately before the first period that will
be imported. Use that statement date as `opening_balance_date`, then import
transactions beginning after that date. Transactions dated on the opening
balance date are excluded from subsequent balance calculation, so the imported
range should normally begin on the following day.

Do not guess an opening balance. If the preceding statement cannot establish a
reliable closing balance, resolve that gap before importing. Keep each currency
and each bank account separate; do not combine checkpoints across accounts or
currencies.

## Pilot discipline

Work through one account completely before starting another. Review duplicates,
matching, categorization, and reconciliation after every import. If a result is
uncertain, stop and preserve the source statement rather than compensating with
an invented transaction or checkpoint. End each verified working session with
a checksummed backup and follow the Phase 7A recovery guide when restoration is
required.

## RBC CSV compatibility and pilot import order

MG5 accepts the exact supported RBC header with or without one leading UTF-8
BOM. Current RBC exports may leave commas in Description 2 unquoted. The reader
reconstructs all fields between Description 1 and the final CAD/USD fields as
Description 2, including multiple commas; standard quoted commas continue to
work normally. Exactly one final currency field must contain an amount.

Every normalized RBC or Triangle row carries its statement currency. MG5
rejects a row when that currency differs from the selected account currency,
before it creates either a transaction or an imported-movement identity. Keep
CAD and USD accounts separate and verify the selected account before upload.

For the initial RBC chequing pilot, import the overlapping exports in this safe
order:

1. `(6)`
2. `(3)`
3. `(7)`

Overlapping rows are expected to be deduplicated while legitimate repeated
occurrences remain distinct. After each file, inspect the import's total,
processed, failed, imported, and duplicate counts before continuing. Stop if a
file has parser failures, an unexpected currency, or counts that do not agree
with the reviewed overlap.
