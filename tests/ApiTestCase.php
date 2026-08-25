<?php

namespace Tests;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function actingAsAdmin(): TestCase
    {
        /** @var Authenticatable $adminUser */
        $adminUser = User::factory()->create(['name' => 'Admin']);
        $adminUser->tenants()->syncWithoutDetaching(Tenant::query()->pluck('id'));

        return $this->actingAs($adminUser);
    }
}
