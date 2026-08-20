<?php

namespace Tests\Feature;

use App\Events\TransactionsUpdatedEvent;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TransactionCrudCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_users_can_create_read_update_list_and_delete_transactions(): void
    {
        Event::fake([TransactionsUpdatedEvent::class]);
        $this->actingAs(User::factory()->create());
        $account = Account::factory()->create([
            'account_number' => 'CHEQUING-1',
            'name' => 'Chequing',
            'type' => 'debit',
        ]);
        $category = Category::factory()->create(['name' => 'Groceries']);
        $payload = [
            'account' => ['account_number' => 'CHEQUING-1'],
            'category' => ['id' => $category->id],
            'transaction_date' => '2024-02-15',
            'description' => 'LOCAL MARKET',
            'amount' => -42.50,
            'rule_content' => null,
        ];

        $id = $this->postJson('/api/transactions', $payload)
            ->assertCreated()
            ->assertJsonPath('data.description', 'LOCAL MARKET')
            ->json('data.id');

        $this->getJson("/api/transactions/{$id}")
            ->assertOk()
            ->assertJsonPath('data.amount', -42.5);

        $this->getJson('/api/transactions')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $payload['description'] = 'UPDATED MARKET';
        $payload['amount'] = -40;
        $this->putJson("/api/transactions/{$id}", $payload)
            ->assertOk()
            ->assertJsonPath('data.description', 'UPDATED MARKET');

        $this->deleteJson("/api/transactions/{$id}")->assertNoContent();
        $this->assertDatabaseMissing(Transaction::class, ['id' => $id]);
        Event::assertDispatched(TransactionsUpdatedEvent::class, 2);
    }
}
