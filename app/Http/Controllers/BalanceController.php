<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BalanceController extends Controller
{
    public function index()
    {
        $account_number = '06402-5031752';

        $monthlyBalances = Transaction::query()
        ->where('account_number', $account_number)
        ->groupBy(DB::raw('YEAR(transaction_date)'))
        ->groupBy(DB::raw('MONTH(transaction_date)'))
        ->select([
            DB::raw('YEAR(transaction_date) as year'),
            DB::raw('MONTH(transaction_date) as month'),
            DB::raw('SUM(amount) as balance'),
        ])
        ->orderBy('year')
        ->orderBy('month')
        ->get();

        return $monthlyBalances;
    }
    //
}
