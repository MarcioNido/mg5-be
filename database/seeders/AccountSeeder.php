<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

class AccountSeeder extends Seeder
{
    public function run()
    {
        Account::factory()->debit()->create(['number' => '06402-5031752', 'name' => 'Marcio RBC Chequing']);
        Account::factory()->debit()->create(['number' => '06402-5039466', 'name' => 'Monica RBC Chequing']);
    }
}
