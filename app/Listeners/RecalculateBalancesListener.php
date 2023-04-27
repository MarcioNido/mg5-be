<?php

namespace App\Listeners;

use App\Models\Account;
use App\Models\Balance;
use App\Models\Transaction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RecalculateBalancesListener implements ShouldQueue
{
    public function handle()
    {
        Account::query()
            ->get()
            ->each(function (Account $account) {
                $this->recalculateBalance($account);
            });
    }

    private function recalculateBalance(Account $account)
    {
        $monthlyBalances = Transaction::query()
            ->where("account_number", $account->account_number)
            ->groupBy(DB::raw("YEAR(transaction_date)"))
            ->groupBy(DB::raw("MONTH(transaction_date)"))
            ->select([
                DB::raw("YEAR(transaction_date) as year"),
                DB::raw("MONTH(transaction_date) as month"),
                DB::raw("SUM(amount) as balance"),
            ])
            ->orderBy("year")
            ->orderBy("month")
            ->get();

        $balance = 0;

        foreach ($monthlyBalances as $monthlyBalance) {
            $balance += $monthlyBalance->balance;
            Balance::query()->updateOrCreate(
                [
                    "account_number" => (string) $account->account_number,
                    "last_day_of_month" => Carbon::create(
                        $monthlyBalance->year,
                        $monthlyBalance->month
                    )
                        ->lastOfMonth()
                        ->toDateString(),
                ],
                [
                    "final_balance" => $balance,
                ]
            );
        }
    }
}
