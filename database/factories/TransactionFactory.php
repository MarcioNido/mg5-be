<?php

namespace Database\Factories;

use App\Enums\TransactionOrigin;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'account_id' => Account::factory(),
            'transaction_date' => fake()->date(),
            'description' => fake()->sentence(3),
            'amount' => fake()->randomElement(['-25.0000', '100.0000']),
            'status' => TransactionStatus::Pending,
            'origin' => TransactionOrigin::Manual,
        ];
    }
}
