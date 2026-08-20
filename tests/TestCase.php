<?php

namespace Tests;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        if (Schema::hasTable('tenants')) {
            Tenant::query()
                ->where('slug', 'personal')
                ->first()
                ?->makeCurrent();
        }
    }

    protected function tearDown(): void
    {
        Tenant::forgetCurrent();

        parent::tearDown();
    }
}
