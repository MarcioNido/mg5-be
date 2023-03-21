<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory
 */
class AccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'name' => fake()->sentence(3),
            'type' => fake()->randomElement(['credit', 'debit', 'investment']),
        ];
    }

    public function credit(): AccountFactory
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'credit',
        ]);
    }

    public function debit(): AccountFactory
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'debit',
        ]);
    }

    public function investment(): AccountFactory
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'investment',
        ]);
    }
}
