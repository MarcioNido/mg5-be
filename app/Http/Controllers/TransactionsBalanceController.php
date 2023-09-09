<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class TransactionsBalanceController extends Controller
{
    public function monthlyBalance(int $month, int $year): array
    {
        // total income group by month, last 12 months
        $income = Transaction::query()
            ->selectRaw(
                "YEAR(transaction_date) as year, MONTH(transaction_date) as month, sum(amount) as total"
            )
            ->where("transaction_date", ">=", now()->subMonths(12))
            ->where("transaction_date", "<=", now())
            ->whereHas("category", function ($query) {
                $query->whereIn("type", ["income", "deductions"]);
            })
            ->groupBy(
                DB::raw("YEAR(transaction_date)"),
                DB::raw("MONTH(transaction_date)")
            )
            ->get()
            //            ->pluck("total", "month")
            ->toArray();

        $expense = Transaction::query()
            ->selectRaw(
                "YEAR(transaction_date) as year, MONTH(transaction_date) as month, sum(amount) as total"
            )
            ->where("transaction_date", ">=", now()->subMonths(12))
            ->where("transaction_date", "<=", now())
            ->whereHas("category", function ($query) {
                $query->whereIn("type", [
                    "fixed expenses",
                    "variable expenses",
                ]);
            })
            ->groupBy(
                DB::raw("YEAR(transaction_date)"),
                DB::raw("MONTH(transaction_date)")
            )
            ->get()
            //            ->pluck("total", "month")
            ->toArray();

        return [
            "income" => $income,
            "expense" => $expense,
        ];
    }
}
