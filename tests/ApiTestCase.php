<?php

namespace Tests;

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
        $adminUser = User::query()
            ->where("name", "Admin")
            ->firstOrFail();
        return $this->actingAs($adminUser);
    }
}
