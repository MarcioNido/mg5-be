<?php

namespace Tests\Feature;

use App\Enums\TransactionOrigin;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BalanceCalculationCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_balance_uses_the_latest_prior_balance_and_month_transactions(): void
    {
        $this->actingAs(User::factory()->create());
        $account = Account::factory()->create([
            'account_number' => 'CHEQUING-1',
            'name' => 'Chequing',
            'type' => 'debit',
            'opening_balance' => '1000.0000',
            'opening_balance_date' => '2024-01-31',
        ]);
        $this->transaction($account, '2024-02-05', 'Deposit', 250.25);
        $this->transaction($account, '2024-02-10', 'Payment', -75.10);
        $this->transaction($account, '2024-03-01', 'Ignored', 999);

        $this->getJson("/api/balances/{$account->id}/2024-02")
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'initialBalance' => '1000.00',
                    'totalCredits' => '250.25',
                    'totalDebits' => '-75.10',
                    'finalBalance' => '1175.15',
                ],
            ]);
    }

    private function transaction(Account $account, string $date, string $description, float $amount): void
    {
        Transaction::query()->create([
            'account_id' => $account->id,
            'transaction_date' => $date,
            'description' => $description,
            'amount' => $amount,
            'status' => TransactionStatus::Posted,
            'origin' => TransactionOrigin::Csv,
            'posted_at' => now(),
        ]);
    }
}
