<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run()
    {
        $income = Category::factory()->create([
            "name" => "Income",
            "type" => "income",
            "level" => 1,

        ]);

        $grossIncome = Category::factory()->create([
            "name" => "Gross Income",
            "type" => "income",
            "level" => 2,
            "parent_id" => $income->id,
        ]);

        $deductions = Category::factory()->create([
            "name" => "Deductions",
            "type" => "deductions",
            "level" => 1,
        ]);

        $taxes = Category::factory()->create([
            "name" => "Taxes",
            "type" => "deductions",
            "level" => 2,
            "parent_id" => $deductions->id,
        ]);

        $fixedExpenses = Category::factory()->create([
            "name" => "Fixed Expenses",
            "type" => "fixed expenses",
            "level" => 1,
        ]);

        $variableExpenses = Category::factory()->create([
            "name" => "Variable Expenses",
            "type" => "variable expenses",
            "level" => 1,
        ]);

        $financialTransactions = Category::factory()->create([
            "name" => "Financial Transactions",
            "type" => "financial transactions",
            "level" => 1,
        ]);


    }
}
