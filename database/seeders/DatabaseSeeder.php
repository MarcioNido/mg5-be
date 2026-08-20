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

        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@moneyguru.com',
            'password' => Hash::make('12345678'),
        ]);

        $admin->tenants()->sync(Tenant::query()->pluck('id'));

        Tenant::query()->each(function (Tenant $tenant): void {
            $tenant->makeCurrent();
            $this->call(CategorySeeder::class);
        });

        Tenant::forgetCurrent();
    }
}
