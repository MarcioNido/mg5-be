<?php

namespace Tests\Feature;

use App\Jobs\ProcessRule;
use App\Models\Account;
use App\Models\Category;
use App\Models\Rule;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategorizationRuleCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_rule_only_categorizes_matching_uncategorized_transactions_by_default(): void
    {
        $account = Account::factory()->create([
            'account_number' => 'CHEQUING-1',
            'name' => 'Chequing',
            'type' => 'debit',
        ]);
        $matchingCategory = Category::factory()->create(['name' => 'Groceries']);
        $existingCategory = Category::factory()->create(['name' => 'Existing']);
        $rule = Rule::query()->create([
            'content' => '%MARKET%',
            'account_id' => $account->id,
            'category_id' => $matchingCategory->id,
        ]);

        $uncategorized = $this->transaction($account, 'LOCAL MARKET');
        $alreadyCategorized = $this->transaction($account, 'OTHER MARKET', $existingCategory);
        $nonMatching = $this->transaction($account, 'COFFEE SHOP');

        (new ProcessRule($rule))->handle();

        $this->assertSame($matchingCategory->id, $uncategorized->fresh()->category_id);
        $this->assertSame($existingCategory->id, $alreadyCategorized->fresh()->category_id);
        $this->assertNull($nonMatching->fresh()->category_id);
    }

    private function transaction(Account $account, string $description, ?Category $category = null): Transaction
    {
        return Transaction::query()->create([
            'account_id' => $account->id,
            'transaction_date' => '2024-02-15',
            'description' => $description,
            'amount' => -10,
            'category_id' => $category?->id,
        ]);
    }
}
