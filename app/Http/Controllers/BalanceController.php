<?php

namespace App\Http\Controllers;

use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\Money;
use App\Services\ReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class BalanceController extends Controller
{
    /**
     * Special endpoint
     * /api/balances/{accountNumber}/{year}/{month}
     * Will return the initial balance, total credits, total debits, final balance
     * Let's see how it goes :)
     */
    public function show(Account $account, string $month)
    {
        [$initialBalance, $totalCredits, $totalDebits] = $this->getTotals(
            $account,
            $month
        );

        return new JsonResponse([
            'data' => [
                'initialBalance' => Money::formatUnits($initialBalance),
                'totalCredits' => Money::formatUnits($totalCredits),
                'totalDebits' => Money::formatUnits($totalDebits),
                'finalBalance' => Money::formatUnits($initialBalance + $totalCredits + $totalDebits),
            ],
        ]);
    }

    private function getTotals($account, $month)
    {
        $monthDate = Carbon::create($month);

        $initialBalance = Money::units(app(ReconciliationService::class)->calculate(
            $account,
            $monthDate->copy()->startOfMonth()->subDay()
        ));

        $monthTransactions = Transaction::query()
            ->financiallyActive()
            ->selectRaw(
                'SUM(CASE WHEN amount >= 0 THEN amount ELSE 0 END) as credits'
            )
            ->selectRaw(
                'SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END) as debits'
            )
            ->where('account_id', $account->id)
            ->where('status', TransactionStatus::Posted->value)
            ->where(
                'transaction_date',
                '>=',
                $monthDate->startOfMonth()->toDateString()
            )
            ->where(
                'transaction_date',
                '<=',
                $monthDate->endOfMonth()->toDateString()
            )
            ->first();

        $totalCredits = Money::units($monthTransactions->credits ?? '0');
        $totalDebits = Money::units($monthTransactions->debits ?? '0');

        return [$initialBalance, $totalCredits, $totalDebits];
    }
}
