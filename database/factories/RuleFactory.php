<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Rule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rule>
 */
class RuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'content' => fake()->word(),
            'account_id' => null,
            'category_id' => Category::factory(),
        ];
    }
}
