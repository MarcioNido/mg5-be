<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Support\Carbon;

class TransactionsSummaryController extends Controller
{
    public function monthlyIncome(int $month, int $year): array
    {
        $transactions = Transaction::query()
            ->financiallyActive()
            ->where(
                'transaction_date',
                '>=',
                Carbon::createFromDate($year, $month, 1)
            )
            ->where(
                'transaction_date',
                '<=',
                Carbon::createFromDate($year, $month, 1)->endOfMonth()
            )
            ->whereHas('category', function ($query) {
                $query->where('type', 'income');
            })
            ->get();

        $income = $transactions->sum('amount');

        return [
            'income' => $income,
            'transactions' => $transactions,
        ];
    }

    public function monthlyExpense(int $month, int $year): array
    {
        $transactions = Transaction::query()
            ->financiallyActive()
            ->where(
                'transaction_date',
                '>=',
                Carbon::createFromDate($year, $month, 1)
            )
            ->where(
                'transaction_date',
                '<=',
                Carbon::createFromDate($year, $month, 1)->endOfMonth()
            )
            ->whereHas('category', function ($query) {
                $query->where('type', 'expense');
            })
            ->get();

        $expense = $transactions->sum('amount');

        return [
            'expense' => $expense,
            'transactions' => $transactions,
        ];
    }
}
