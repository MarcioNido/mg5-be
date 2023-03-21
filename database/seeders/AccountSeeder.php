<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

class AccountSeeder extends Seeder
{
    public function run()
    {
        Account::factory()->debit()->create(['name' => 'Marcio RBC Chequing', 'number' => '06402-5031752']);
        Account::factory()->debit()->create(['name' => 'Monica RBC Chequing', 'number' => '06402-5039466']);
    }
}
