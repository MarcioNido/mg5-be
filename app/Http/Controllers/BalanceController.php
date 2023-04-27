<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Balance;
use App\Models\Transaction;
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
            "data" => [
                "initialBalance" => $this->currency($initialBalance),
                "totalCredits" => $this->currency($totalCredits),
                "totalDebits" => $this->currency($totalDebits),
                "finalBalance" => $this->currency(
                    $initialBalance + $totalCredits + $totalDebits
                ),
            ],
        ]);
    }

    private function getTotals($account, $month)
    {
        $monthDate = Carbon::create($month);

        $initialBalance =
            Balance::query()
                ->where("account_number", $account->account_number)
                ->where("last_day_of_month", "<", $monthDate->lastOfMonth())
                ->orderBy("last_day_of_month", "desc")
                ->value("final_balance") ?? 0;

        $monthTransactions = Transaction::query()
            ->selectRaw("SUM(IF(amount >= 0, amount, 0)) as credits")
            ->selectRaw("SUM(IF(amount < 0, amount, 0)) as debits")
            ->where("account_number", $account->account_number)
            ->where(
                "transaction_date",
                ">=",
                $monthDate->startOfMonth()->toDateString()
            )
            ->where(
                "transaction_date",
                "<=",
                $monthDate->endOfMonth()->toDateString()
            )
            ->first();

        $totalCredits = $monthTransactions->credits;
        $totalDebits = $monthTransactions->debits;

        return [$initialBalance, $totalCredits, $totalDebits];
    }

    public function currency(mixed $initialBalance): string
    {
        return number_format((float) $initialBalance, 2, ".", "");
    }
}
