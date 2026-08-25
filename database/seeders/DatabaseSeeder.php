<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        $this->call(TenantSeeder::class);

        Tenant::forgetCurrent();
        $this->call(CategorySeeder::class);
    }
}
