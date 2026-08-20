<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::query()->updateOrCreate(['slug' => 'personal'], ['name' => 'Personal']);
        Tenant::query()->updateOrCreate(['slug' => 'clinic'], ['name' => 'Clinic']);
    }
}
