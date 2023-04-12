<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

class AccountSeeder extends Seeder
{
    public function run()
    {
        Account::factory()->debit()->create(['account_number' => '06402-5031752', 'name' => 'Marcio RBC Chequing']);
        Account::factory()->debit()->create(['account_number' => '06402-5032370', 'name' => 'Marcio RBC Savings']);
        Account::factory()->debit()->create(['account_number' => '06402-5039466', 'name' => 'Monica RBC Chequing']);
        Account::factory()->credit()->create(['account_number' => '4514093608902876', 'name' => 'Visa Avion RBC Marcio']);
    }
}
