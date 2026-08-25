<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

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

        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@moneyguru.com'],
            [
                'name' => 'Admin',
                'password' => Hash::make('12345678'),
            ]
        );

        $admin->tenants()->syncWithoutDetaching(Tenant::query()->pluck('id'));

        Tenant::forgetCurrent();
        $this->call(CategorySeeder::class);
    }
}
