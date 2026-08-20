<?php

namespace Tests\Http\Controllers\CategoryController;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryControllerIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_index(): void
    {
        $this->actingAs(User::factory()->create());
        $parent = Category::factory()->create([
            'name' => 'Expenses',
            'type' => 'fixed expenses',
        ]);
        Category::factory()->create([
            'name' => 'Rent',
            'parent_id' => $parent->id,
            'type' => 'fixed expenses',
        ]);

        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Expenses'])
            ->assertJsonFragment([
                'name' => 'Rent',
                'level' => 2,
                'type' => 'fixed expenses',
            ]);
    }
}
