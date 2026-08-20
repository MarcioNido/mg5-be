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
            'account_number' => null,
            'name' => fake()->sentence(3),
            'type' => fake()->randomElement(['credit', 'chequing', 'savings', 'investment']),
            'currency' => 'CAD',
            'opening_balance' => '0.0000',
            'opening_balance_date' => null,
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
