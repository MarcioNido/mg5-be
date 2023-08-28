<?php

namespace Tests\Http\Controllers\CategoryController;

use Tests\ApiTestCase;

class CategoryControllerIndexTest extends ApiTestCase
{
    public function testIndex(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson("/api/categories");

        $response->assertOk();

        dd($response->json());
    }
}
