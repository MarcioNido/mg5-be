<?php

namespace Tests\Feature;

use App\Jobs\ProcessAllRules;
use App\Models\Account;
use App\Models\Category;
use App\Models\Rule;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionSplitService;
use Database\Seeders\CategorySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\ApiTestCase;

class Phase5ECategoriesRulesApiTest extends ApiTestCase
{
    public function test_category_api_requires_authentication_membership_and_isolates_tenants(): void
    {
        $this->getJson('/api/categories')->assertUnauthorized();

        $outsider = User::factory()->create();
        $this->actingAs($outsider)
            ->withHeader('X-Tenant-Slug', 'clinic')
            ->getJson('/api/categories')
            ->assertForbidden();

        $this->actingAsAdmin();
        $clinic = Tenant::query()->where('slug', 'clinic')->firstOrFail();
        $clinicCategory = $clinic->execute(fn () => Category::factory()->create(['name' => 'Clinic private']));

        $this->withHeader('X-Tenant-Slug', 'personal')
            ->getJson("/api/categories/{$clinicCategory->id}")
            ->assertNotFound();
        $this->postJson('/api/categories', [
            'name' => 'Invalid cross tenant child',
            'type' => 'expense',
            'parent_id' => $clinicCategory->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    }

    public function test_category_flat_contract_ordering_creation_and_computed_levels(): void
    {
        $this->actingAsAdmin();
        $rootId = $this->postJson('/api/categories', [
            'name' => '  Zeta group  ',
            'type' => 'expense',
            'parent_id' => null,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Zeta group')
            ->assertJsonPath('data.level', 1)
            ->json('data.id');

        $childId = $this->postJson('/api/categories', [
            'name' => 'Alpha child',
            'type' => 'transfer',
            'parent_id' => $rootId,
        ])->assertCreated()
            ->assertJsonPath('data.level', 2)
            ->assertJsonPath('data.parent.id', $rootId)
            ->json('data.id');

        $items = $this->getJson('/api/categories')->assertOk()->json('data');
        $child = collect($items)->firstWhere('id', $childId);
        $this->assertSame(['id', 'name', 'type', 'level', 'parent'], array_keys($child));
        $this->assertSame(
            collect($items)->sortBy(fn (array $item) => [$item['type'], mb_strtolower($item['name']), $item['id']])->pluck('id')->all(),
            collect($items)->pluck('id')->all()
        );
    }

    public function test_category_update_recalculates_subtree_levels_and_allows_independent_type_changes(): void
    {
        $this->actingAsAdmin();
        $child = Category::factory()->create(['name' => 'Movable']);
        $detail = Category::factory()->create(['parent_id' => $child->id, 'name' => 'Detail']);
        $newRoot = Category::factory()->create(['name' => 'New hierarchy', 'type' => 'expense']);

        $this->patchJson("/api/categories/{$child->id}", [
            'name' => 'Moved and renamed',
            'type' => 'transfer',
            'parent_id' => $newRoot->id,
        ])->assertOk()
            ->assertJsonPath('data.level', 2)
            ->assertJsonPath('data.type', 'transfer');

        $this->assertSame(3, $detail->fresh()->level);
        $this->assertSame($newRoot->id, $child->fresh()->parent_id);
        $this->assertNotSame($newRoot->type, $child->fresh()->type);
    }

    public function test_category_rejects_cycles_excess_depth_and_case_insensitive_sibling_duplicates(): void
    {
        $this->actingAsAdmin();
        $root = Category::factory()->create(['name' => 'Cycle root']);
        $child = Category::factory()->create(['parent_id' => $root->id, 'name' => 'Cycle child']);
        $detail = Category::factory()->create(['parent_id' => $child->id, 'name' => 'Cycle detail']);
        $otherRoot = Category::factory()->create(['name' => 'Other parent']);

        $this->patchJson("/api/categories/{$root->id}", ['parent_id' => $root->id])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->patchJson("/api/categories/{$root->id}", ['parent_id' => $detail->id])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->postJson('/api/categories', [
            'name' => 'Too deep', 'type' => 'expense', 'parent_id' => $detail->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->postJson('/api/categories', [
            'name' => ' cycle CHILD ', 'type' => 'expense', 'parent_id' => $root->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->postJson('/api/categories', [
            'name' => 'Cycle child', 'type' => 'expense', 'parent_id' => $otherRoot->id,
        ])->assertCreated();
    }

    public function test_category_deletion_is_soft_and_blocked_for_every_management_reference(): void
    {
        $this->actingAsAdmin();
        $account = Account::factory()->create();

        $parent = Category::factory()->create(['name' => 'Deletion parent']);
        $child = Category::factory()->create(['parent_id' => $parent->id]);
        $direct = Category::factory()->create(['name' => 'Direct use']);
        $this->transaction($account, 'Direct', $direct);
        $splitCategory = Category::factory()->create(['name' => 'Split use']);
        $splitTransaction = $this->transaction($account, 'Split');
        app(TransactionSplitService::class)->replace($splitTransaction, [[
            'category_id' => $splitCategory->id, 'amount' => '-10.0000',
        ]]);
        $ruleCategory = Category::factory()->create(['name' => 'Rule use']);
        Rule::factory()->create(['category_id' => $ruleCategory->id]);
        $unused = Category::factory()->create(['name' => 'Unused leaf']);

        $this->deleteJson("/api/categories/{$parent->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->deleteJson("/api/categories/{$child->id}")->assertNoContent();
        $this->deleteJson("/api/categories/{$parent->id}")->assertNoContent();
        $this->assertSoftDeleted($child);
        $this->assertSoftDeleted($parent);
        $this->deleteJson("/api/categories/{$direct->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->deleteJson("/api/categories/{$splitCategory->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->deleteJson("/api/categories/{$ruleCategory->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->deleteJson("/api/categories/{$unused->id}")->assertNoContent();
        $this->assertSoftDeleted($unused);
        $this->getJson("/api/categories/{$unused->id}")->assertNotFound();
    }

    public function test_soft_deleted_categories_are_rejected_by_category_transaction_split_and_rule_inputs(): void
    {
        $this->actingAsAdmin();
        $account = Account::factory()->create();
        $deleted = Category::factory()->create(['name' => 'Deleted target']);
        $deleted->delete();

        $this->postJson('/api/categories', [
            'name' => 'Child', 'type' => 'expense', 'parent_id' => $deleted->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->postJson('/api/transactions', [
            'account_id' => $account->id,
            'transaction_date' => '2026-08-24',
            'description' => 'Deleted category',
            'amount' => '-10.0000',
            'category_id' => $deleted->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('category_id');

        $transactionCount = DB::table('transactions')->count();
        $splitCount = DB::table('transaction_splits')->count();
        $this->postJson('/api/transactions', [
            'account_id' => $account->id,
            'transaction_date' => '2026-08-24',
            'description' => 'Rejected deleted split category',
            'amount' => '-10.0000',
            'splits' => [[
                'category_id' => $deleted->id,
                'amount' => '-10.0000',
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('splits');
        $this->assertSame($transactionCount, DB::table('transactions')->count());
        $this->assertSame($splitCount, DB::table('transaction_splits')->count());

        $this->postJson('/api/rules', [
            'match_text' => 'deleted', 'category_id' => $deleted->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('category_id');
    }

    public function test_rule_crud_uses_flat_safe_paginated_contract_and_queues_reprocessing(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $account = Account::factory()->create([
            'account_number' => 'PRIVATE', 'name' => 'Chequing', 'type' => 'chequing', 'currency' => 'CAD',
        ]);
        $parent = Category::factory()->create(['name' => 'Rule parent']);
        $category = Category::factory()->create(['parent_id' => $parent->id, 'name' => 'Rule category']);

        $id = $this->postJson('/api/rules', [
            'match_text' => '  Market  ', 'account_id' => $account->id, 'category_id' => $category->id,
        ])->assertCreated()
            ->assertJsonPath('data.match_text', 'Market')
            ->assertJsonPath('data.account.id', $account->id)
            ->assertJsonPath('data.category.parent.id', $parent->id)
            ->json('data.id');
        $this->assertDatabaseHas('rules', ['id' => $id, 'content' => 'Market']);
        Queue::assertPushed(ProcessAllRules::class);

        $item = $this->getJson('/api/rules?per_page=1&search=MARK&account_id='.$account->id.'&category_id='.$category->id)
            ->assertOk()->assertJsonPath('meta.per_page', 1)->assertJsonPath('meta.total', 1)->json('data.0');
        $this->assertSame(['id', 'match_text', 'account', 'category', 'created_at', 'updated_at'], array_keys($item));
        $this->assertSame(['id', 'name', 'type', 'currency'], array_keys($item['account']));
        $this->assertArrayNotHasKey('account_number', $item['account']);

        $this->patchJson("/api/rules/{$id}", ['match_text' => 'Grocer', 'account_id' => null])
            ->assertOk()->assertJsonPath('data.account', null)->assertJsonPath('data.match_text', 'Grocer');
        $this->deleteJson("/api/rules/{$id}")->assertNoContent();
        $this->getJson("/api/rules/{$id}")->assertNotFound();
    }

    public function test_rule_filters_validate_cross_tenant_and_soft_deleted_references(): void
    {
        $this->actingAsAdmin();
        $personal = Tenant::current();
        $clinic = Tenant::query()->where('slug', 'clinic')->firstOrFail();
        [$account, $category, $rule] = $clinic->execute(fn () => [
            Account::factory()->create(), Category::factory()->create(), Rule::factory()->create(),
        ]);
        $personal->makeCurrent();

        $this->getJson("/api/rules?account_id={$account->id}")->assertUnprocessable()->assertJsonValidationErrors('account_id');
        $this->getJson("/api/rules?category_id={$category->id}")->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->getJson("/api/rules/{$rule->id}")->assertNotFound();
        $this->getJson('/api/rules?per_page=51')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    public function test_category_and_rule_queries_fail_closed_without_a_current_tenant(): void
    {
        $this->actingAsAdmin();
        Category::factory()->create(['name' => 'Fail closed category']);
        Rule::factory()->create(['content' => 'fail closed']);

        Tenant::forgetCurrent();

        $this->assertSame(0, Category::query()->count());
        $this->assertSame(0, Rule::query()->count());
    }

    public function test_rule_list_orders_case_insensitively_with_id_tie_breaker_and_preserves_query_parameters(): void
    {
        $this->actingAsAdmin();
        $category = Category::factory()->create(['name' => 'Ordered rule category']);
        $zulu = Rule::factory()->create(['content' => 'Zeta', 'category_id' => $category->id]);
        $alphaFirst = Rule::factory()->create(['content' => 'alpha', 'category_id' => $category->id]);
        $alphaSecond = Rule::factory()->create(['content' => 'ALPHA', 'category_id' => $category->id]);

        $response = $this->getJson('/api/rules?per_page=2&search=a')
            ->assertOk()->assertJsonPath('meta.total', 3);
        $this->assertSame([$alphaFirst->id, $alphaSecond->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertStringContainsString('per_page=2', $response->json('links.next'));
        $this->assertStringContainsString('search=a', $response->json('links.next'));
        $this->getJson('/api/rules?per_page=2&search=a&page=2')
            ->assertOk()->assertJsonPath('data.0.id', $zulu->id);
    }

    public function test_rules_match_literal_text_case_insensitively_without_overwriting_categorized_or_split_transactions(): void
    {
        $this->actingAsAdmin();
        $account = Account::factory()->create();
        $otherAccount = Account::factory()->create();
        $target = Category::factory()->create(['name' => 'Literal target']);
        $existing = Category::factory()->create(['name' => 'Existing target']);
        Rule::factory()->create(['content' => 'A%_\\B', 'account_id' => null, 'category_id' => $target->id]);

        $literal = $this->transaction($account, 'prefix a%_\\b suffix');
        $wildcardNearMiss = $this->transaction($account, 'prefix axxxb suffix');
        $categorized = $this->transaction($otherAccount, 'A%_\\B', $existing);
        $split = $this->transaction($otherAccount, 'A%_\\B');
        app(TransactionSplitService::class)->replace($split, [[
            'category_id' => $existing->id, 'amount' => '-10.0000',
        ]]);

        (new ProcessAllRules)->handle();

        $this->assertSame($target->id, $literal->fresh()->category_id);
        $this->assertNull($wildcardNearMiss->fresh()->category_id);
        $this->assertSame($existing->id, $categorized->fresh()->category_id);
        $this->assertNull($split->fresh()->category_id);

        (new ProcessAllRules(true))->handle();
        $this->assertNull($split->fresh()->category_id);
    }

    public function test_account_specific_rules_and_first_rule_wins_deterministically(): void
    {
        $this->actingAsAdmin();
        $wanted = Account::factory()->create();
        $other = Account::factory()->create();
        $firstCategory = Category::factory()->create(['name' => 'First winner']);
        $secondCategory = Category::factory()->create(['name' => 'Second winner']);
        Rule::factory()->create(['content' => 'coffee', 'account_id' => $wanted->id, 'category_id' => $firstCategory->id]);
        Rule::factory()->create(['content' => 'coffee', 'account_id' => null, 'category_id' => $secondCategory->id]);
        $wantedTransaction = $this->transaction($wanted, 'COFFEE SHOP');
        $otherTransaction = $this->transaction($other, 'Coffee shop');

        (new ProcessAllRules)->handle();

        $this->assertSame($firstCategory->id, $wantedTransaction->fresh()->category_id);
        $this->assertSame($secondCategory->id, $otherTransaction->fresh()->category_id);
    }

    public function test_rule_update_and_delete_do_not_undo_historical_categorizations(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $account = Account::factory()->create();
        $category = Category::factory()->create(['name' => 'Historical category']);
        $rule = Rule::factory()->create(['content' => 'historic', 'category_id' => $category->id]);
        $transaction = $this->transaction($account, 'Historic purchase');
        (new ProcessAllRules)->handle();

        $this->patchJson("/api/rules/{$rule->id}", ['match_text' => 'new text'])->assertOk();
        $this->deleteJson("/api/rules/{$rule->id}")->assertNoContent();
        $this->assertSame($category->id, $transaction->fresh()->category_id);
    }

    public function test_category_and_rule_lists_have_bounded_query_counts(): void
    {
        $this->actingAsAdmin();
        $account = Account::factory()->create();
        $parent = Category::factory()->create(['name' => 'Query parent']);
        foreach (range(1, 25) as $index) {
            $category = Category::factory()->create(['parent_id' => $parent->id, 'name' => "Query child {$index}"]);
            Rule::factory()->create(['content' => "query {$index}", 'account_id' => $account->id, 'category_id' => $category->id]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/categories')->assertOk();
        $categoryQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->getJson('/api/rules?per_page=25')->assertOk();
        $ruleQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(5, $categoryQueries);
        $this->assertLessThanOrEqual(8, $ruleQueries);
    }

    public function test_recommended_plans_are_distinct_idempotent_and_non_destructive(): void
    {
        $personal = Tenant::query()->where('slug', 'personal')->firstOrFail();
        $clinic = Tenant::query()->where('slug', 'clinic')->firstOrFail();
        $unrelated = $personal->execute(fn () => Category::factory()->create([
            'name' => 'My custom category', 'type' => 'transfer',
        ]));

        foreach ([$personal, $clinic] as $tenant) {
            $tenant->execute(function (): void {
                $before = Category::query()->count();
                app(CategorySeeder::class)->run();
                $this->assertSame($before, Category::query()->count());
            });
        }

        $personal->execute(function () use ($unrelated): void {
            $this->assertTrue(Category::query()->where('name', 'Savings and investments')->where('type', 'transfer')->exists());
            $this->assertFalse(Category::query()->where('name', 'OHIP')->exists());
            $this->assertSame('transfer', $unrelated->fresh()->type);
        });
        $clinic->execute(function (): void {
            $this->assertTrue(Category::query()->where('name', 'OHIP')->where('level', 2)->where('type', 'income')->exists());
            $this->assertFalse(Category::query()->where('name', 'Groceries')->exists());
        });
    }

    public function test_category_seeder_direct_execution_without_a_current_tenant_installs_both_plans_idempotently(): void
    {
        $personal = Tenant::query()->where('slug', 'personal')->firstOrFail();
        $clinic = Tenant::query()->where('slug', 'clinic')->firstOrFail();

        $personal->execute(function (): void {
            Category::query()->where('name', 'Groceries')->firstOrFail()->forceDelete();
        });
        $clinic->execute(function (): void {
            Category::query()->where('name', 'OHIP')->firstOrFail()->forceDelete();
        });
        Tenant::forgetCurrent();

        $this->seed(CategorySeeder::class);

        $this->assertNull(Tenant::current());
        $personalCount = $personal->execute(function (): int {
            $this->assertTrue(Category::query()->where('name', 'Groceries')->exists());

            return Category::query()->count();
        });
        $clinicCount = $clinic->execute(function (): int {
            $this->assertTrue(Category::query()->where('name', 'OHIP')->exists());

            return Category::query()->count();
        });
        Tenant::forgetCurrent();

        $this->seed(CategorySeeder::class);

        $this->assertNull(Tenant::current());
        $personal->execute(fn () => $this->assertSame($personalCount, Category::query()->count()));
        $clinic->execute(fn () => $this->assertSame($clinicCount, Category::query()->count()));
        Tenant::forgetCurrent();
    }

    private function transaction(Account $account, string $description, ?Category $category = null): Transaction
    {
        return Transaction::factory()->create([
            'account_id' => $account->id,
            'transaction_date' => '2026-08-24',
            'description' => $description,
            'amount' => '-10.0000',
            'category_id' => $category?->id,
        ]);
    }
}
