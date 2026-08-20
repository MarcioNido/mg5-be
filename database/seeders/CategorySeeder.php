<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run()
    {
        $income = Category::factory()->create([
            'name' => 'Income',
            'type' => 'income',
            'level' => 1,

        ]);

        Category::factory()->create(['name' => 'Operating Expenses', 'type' => 'expense']);
        Category::factory()->create(['name' => 'Transfers', 'type' => 'financial transactions']);
    }
}
