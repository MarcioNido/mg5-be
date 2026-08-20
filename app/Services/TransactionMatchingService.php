<?php

namespace App\Services;

use App\Enums\ImportRowStatus;
use App\Enums\MatchSuggestionStatus;
use App\Enums\TransactionOrigin;
use App\Enums\TransactionStatus;
use App\Models\ImportedMovement;
use App\Models\ImportRow;
use App\Models\MatchSuggestion;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TransactionMatchingService
{
    public function process(ImportRow $row, array $bankData): Transaction
    {
        $candidates = Transaction::query()
            ->where('account_id', $row->account_id)
            ->where('status', TransactionStatus::Pending->value)
            ->where('amount', Money::decimal(Money::units($bankData['amount'])))
            ->whereBetween('transaction_date', [
                Carbon::parse($bankData['transaction_date'], config('app.business_timezone'))->subDays(3)->toDateString(),
                Carbon::parse($bankData['transaction_date'], config('app.business_timezone'))->addDays(3)->toDateString(),
            ])
            ->get()
            ->filter(fn (Transaction $candidate): bool => $this->descriptionConfidence(
                $candidate->description,
                $bankData['description']
            ) >= 0.75)
            ->values();

        if ($candidates->count() === 1) {
            $matched = $this->applyBankData($row, $candidates->first(), $bankData, ImportRowStatus::Matched);
            $this->syncImportedMovement($row, $matched);

            return $matched;
        }

        $posted = Transaction::query()->create([
            ...$bankData,
            'account_id' => $row->account_id,
            'status' => TransactionStatus::Posted,
            'origin' => TransactionOrigin::Csv,
            'posted_at' => now(),
        ]);

        if ($candidates->count() > 1) {
            foreach ($candidates as $candidate) {
                MatchSuggestion::query()->create([
                    'import_row_id' => $row->id,
                    'pending_transaction_id' => $candidate->id,
                    'status' => MatchSuggestionStatus::Pending,
                    'confidence' => $this->descriptionConfidence($candidate->description, $bankData['description']),
                ]);
            }
            $row->update(['transaction_id' => $posted->id, 'status' => ImportRowStatus::NeedsReview]);
        } else {
            $row->update(['transaction_id' => $posted->id, 'status' => ImportRowStatus::Imported]);
        }

        $this->syncImportedMovement($row, $posted);

        return $posted;
    }

    public function confirm(MatchSuggestion $suggestion): Transaction
    {
        return DB::transaction(function () use ($suggestion): Transaction {
            $lockedSuggestion = MatchSuggestion::query()->whereKey($suggestion->id)->lockForUpdate()->firstOrFail();
            $this->ensure($lockedSuggestion->status === MatchSuggestionStatus::Pending, 'This suggestion is no longer pending.');

            $row = ImportRow::query()->whereKey($lockedSuggestion->import_row_id)->lockForUpdate()->firstOrFail();
            $this->ensure($row->status === ImportRowStatus::NeedsReview, 'The import row is no longer awaiting review.');

            $movement = ImportedMovement::query()->whereKey($row->imported_movement_id)->lockForUpdate()->first();
            $imported = Transaction::query()->whereKey($row->transaction_id)->lockForUpdate()->first();
            $pending = Transaction::query()->whereKey($lockedSuggestion->pending_transaction_id)
                ->with('splits')->lockForUpdate()->first();
            $this->ensure($imported !== null, 'The imported transaction no longer exists.');
            $this->ensure($pending !== null && $pending->status === TransactionStatus::Pending, 'The candidate transaction is no longer pending.');
            $this->ensure(
                $movement !== null && $movement->transaction_id === $imported->id,
                'The imported movement identity is no longer linked to this transaction.'
            );

            $pending->update([
                'account_id' => $imported->account_id,
                'transaction_date' => $imported->transaction_date,
                'amount' => $imported->amount,
                'status' => TransactionStatus::Posted,
                'origin' => TransactionOrigin::Manual,
                'posted_at' => $imported->posted_at ?? now(),
            ]);
            $row->update(['transaction_id' => $pending->id, 'status' => ImportRowStatus::Matched]);
            $movement->update(['transaction_id' => $pending->id]);
            $imported->delete();
            $row->suggestions()
                ->whereKeyNot($lockedSuggestion->id)
                ->where('status', MatchSuggestionStatus::Pending->value)
                ->update(['status' => MatchSuggestionStatus::Rejected]);
            $lockedSuggestion->update(['status' => MatchSuggestionStatus::Confirmed]);

            return $pending->fresh(['splits', 'category']);
        });
    }

    public function reject(MatchSuggestion $suggestion): void
    {
        DB::transaction(function () use ($suggestion): void {
            $lockedSuggestion = MatchSuggestion::query()->whereKey($suggestion->id)->lockForUpdate()->firstOrFail();
            $this->ensure($lockedSuggestion->status === MatchSuggestionStatus::Pending, 'This suggestion is no longer pending.');

            $row = ImportRow::query()->whereKey($lockedSuggestion->import_row_id)->lockForUpdate()->firstOrFail();
            $this->ensure($row->status === ImportRowStatus::NeedsReview, 'The import row is no longer awaiting review.');
            $this->ensure(
                Transaction::query()->whereKey($row->transaction_id)->lockForUpdate()->exists(),
                'The imported transaction no longer exists.'
            );

            $lockedSuggestion->update(['status' => MatchSuggestionStatus::Rejected]);
            if (! $row->suggestions()->where('status', MatchSuggestionStatus::Pending->value)->exists()) {
                $row->update(['status' => ImportRowStatus::Imported]);
            }
        });
    }

    private function applyBankData(ImportRow $row, Transaction $transaction, array $bankData, ImportRowStatus $rowStatus): Transaction
    {
        $transaction->update([
            'account_id' => $row->account_id,
            'transaction_date' => $bankData['transaction_date'],
            'amount' => $bankData['amount'],
            'status' => TransactionStatus::Posted,
            'posted_at' => now(),
        ]);
        $row->update(['transaction_id' => $transaction->id, 'status' => $rowStatus]);

        return $transaction->fresh();
    }

    private function descriptionConfidence(?string $left, ?string $right): float
    {
        $normalize = fn (?string $value): string => trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower($value ?? '')));
        $left = $normalize($left);
        $right = $normalize($right);

        if ($left === '' || $right === '') {
            return 1.0;
        }
        if ($left === $right || str_contains($left, $right) || str_contains($right, $left)) {
            return 1.0;
        }

        similar_text($left, $right, $percent);

        return round($percent / 100, 4);
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['suggestion' => $message]);
        }
    }

    private function syncImportedMovement(ImportRow $row, Transaction $transaction): void
    {
        if ($row->imported_movement_id !== null) {
            ImportedMovement::query()->whereKey($row->imported_movement_id)
                ->update(['transaction_id' => $transaction->id]);
        }
    }
}
