<?php

namespace App\Services;

use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Reconciliation;
use App\Models\Transaction;
use Illuminate\Support\Carbon;

class ReconciliationService
{
    public function calculate(Account $account, Carbon|string $statementDate): string
    {
        $date = Carbon::parse($statementDate, config('app.business_timezone'))->toDateString();
        $opening = Money::units($account->opening_balance);
        $transactions = Transaction::query()
            ->financiallyActive()
            ->where('account_id', $account->id)
            ->where('status', TransactionStatus::Posted->value)
            ->when($account->opening_balance_date, fn ($query) => $query->whereDate('transaction_date', '>', $account->opening_balance_date))
            ->whereDate('transaction_date', '<=', $date)
            ->pluck('amount');

        return Money::decimal($transactions->reduce(
            fn (int $carry, string $amount): int => $carry + Money::units($amount),
            $opening
        ));
    }

    public function reconcile(Account $account, string $statementDate, string $enteredBalance): Reconciliation
    {
        $date = Carbon::parse($statementDate, config('app.business_timezone'))->startOfDay();
        $calculated = $this->calculate($account, $date);
        $entered = Money::decimal(Money::units($enteredBalance));

        return Reconciliation::query()->updateOrCreate(
            ['account_id' => $account->id, 'statement_date' => $date],
            [
                'entered_bank_balance' => $entered,
                'calculated_balance' => $calculated,
                'reconciled_at' => Money::units($entered) === Money::units($calculated) ? now() : null,
            ]
        );
    }

    public function reviewPeriod(Account $account, Carbon|string $statementDate): array
    {
        $dateTo = Carbon::parse($statementDate, config('app.business_timezone'))->toDateString();
        $previous = $account->reconciliations()
            ->whereNotNull('reconciled_at')
            ->whereDate('statement_date', '<', $dateTo)
            ->orderByDesc('statement_date')
            ->orderByDesc('id')
            ->first();
        $checkpoint = $previous?->statement_date ?? $account->opening_balance_date;

        return [
            'date_from' => $checkpoint?->copy()->addDay()->toDateString(),
            'date_to' => $dateTo,
            'previous_statement_date' => $previous?->statement_date->toDateString(),
        ];
    }

    public function recalculate(Account $account, ?string $fromDate = null): void
    {
        $account->reconciliations()
            ->when($fromDate, fn ($query) => $query->whereDate('statement_date', '>=', $fromDate))
            ->orderBy('statement_date')
            ->get()
            ->each(function (Reconciliation $reconciliation) use ($account): void {
                $calculated = $this->calculate($account, $reconciliation->statement_date);
                $reconciliation->update([
                    'calculated_balance' => $calculated,
                    'reconciled_at' => Money::units($calculated) === Money::units($reconciliation->entered_bank_balance)
                        ? ($reconciliation->reconciled_at ?? now())
                        : null,
                ]);
            });
    }

    public function recalculateForMutation(Transaction $transaction, array $original): void
    {
        $accountIds = array_unique(array_filter([
            $transaction->account_id,
            $original['account_id'] ?? null,
        ]));
        $dates = array_filter([
            $transaction->transaction_date ? Carbon::parse($transaction->transaction_date)->toDateString() : null,
            isset($original['transaction_date']) ? Carbon::parse($original['transaction_date'])->toDateString() : null,
        ]);
        $fromDate = $dates === [] ? null : min($dates);

        Account::query()->whereKey($accountIds)->get()
            ->each(fn (Account $account) => $this->recalculate($account, $fromDate));
    }

    public function latestValid(Account $account): ?Reconciliation
    {
        return $account->reconciliations()
            ->whereNotNull('reconciled_at')
            ->orderByDesc('statement_date')
            ->orderByDesc('id')
            ->first();
    }
}
