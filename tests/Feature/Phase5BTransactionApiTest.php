<?php

namespace Tests\Feature;

use App\Enums\TransactionOrigin;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Category;
use App\Models\ImportedMovement;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\TransactionSplitService;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

class Phase5BTransactionApiTest extends ApiTestCase
{
    public function test_index_is_paginated_validates_its_limit_and_has_stable_ordering(): void
    {
        $this->actingAsAdmin();
        $account = Account::factory()->create();
        $older = $this->transaction($account, '2026-01-01', ['description' => 'Older']);
        $sameDateFirst = $this->transaction($account, '2026-01-02', ['description' => 'Same date first']);
        $sameDateSecond = $this->transaction($account, '2026-01-02', ['description' => 'Same date second']);

        $response = $this->getJson('/api/transactions?per_page=2&status=pending&page=1')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.id', $sameDateSecond->id)
            ->assertJsonPath('data.1.id', $sameDateFirst->id);

        $this->assertStringContainsString('per_page=2', $response->json('links.next'));
        $this->assertStringContainsString('status=pending', $response->json('links.next'));
        $this->getJson('/api/transactions')->assertOk()->assertJsonPath('meta.per_page', 25);
        $this->getJson('/api/transactions?per_page=51')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
        $this->getJson('/api/transactions?per_page=50')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('data.2.id', $older->id);
    }

    public function test_index_filters_account_status_origin_dates_and_case_insensitive_search(): void
    {
        $this->actingAsAdmin();
        $wantedAccount = Account::factory()->create();
        $otherAccount = Account::factory()->create();
        $wanted = $this->transaction($wantedAccount, '2026-02-15', [
            'description' => 'Vision CLINIC',
            'notes' => 'Annual equipment service',
            'status' => TransactionStatus::Posted,
            'origin' => TransactionOrigin::Csv,
        ]);
        $this->transaction($wantedAccount, '2026-01-01', [
            'description' => 'Outside range',
            'status' => TransactionStatus::Posted,
            'origin' => TransactionOrigin::Csv,
        ]);
        $this->transaction($otherAccount, '2026-02-15', [
            'description' => 'Vision clinic',
            'status' => TransactionStatus::Posted,
            'origin' => TransactionOrigin::Csv,
        ]);

        $query = http_build_query([
            'account_id' => $wantedAccount->id,
            'status' => 'posted',
            'origin' => 'csv',
            'date_from' => '2026-02-15',
            'date_to' => '2026-02-15',
            'search' => 'cLiNiC',
        ]);
        $this->getJson("/api/transactions?{$query}")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $wanted->id);

        $this->getJson('/api/transactions?search=EQUIPMENT')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $wanted->id);
        $this->getJson('/api/transactions?date_from=2026-02-16&date_to=2026-02-15')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_to');
    }

    public function test_category_filter_includes_direct_split_and_all_descendant_categories(): void
    {
        $this->actingAsAdmin();
        $account = Account::factory()->create();
        $group = Category::factory()->create(['name' => 'Operating costs']);
        $child = Category::factory()->create(['parent_id' => $group->id, 'name' => 'Clinic']);
        $grandchild = Category::factory()->create(['parent_id' => $child->id, 'name' => 'Supplies']);
        $direct = $this->transaction($account, '2026-03-01', ['category_id' => $group->id]);
        $descendant = $this->transaction($account, '2026-03-02', ['category_id' => $grandchild->id]);
        $split = $this->transaction($account, '2026-03-03', ['amount' => '-20.0000']);
        app(TransactionSplitService::class)->replace($split, [
            ['category_id' => $child->id, 'amount' => '-20.0000'],
        ]);
        $this->transaction($account, '2026-03-04');

        $ids = collect($this->getJson("/api/transactions?category_id={$group->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$direct->id, $descendant->id, $split->id], $ids);
    }

    public function test_uncategorized_requires_no_direct_category_and_no_splits_and_conflicts_with_category(): void
    {
        $this->actingAsAdmin();
        $account = Account::factory()->create();
        $category = Category::factory()->create();
        $uncategorized = $this->transaction($account, '2026-04-01');
        $this->transaction($account, '2026-04-02', ['category_id' => $category->id]);
        $split = $this->transaction($account, '2026-04-03');
        app(TransactionSplitService::class)->replace($split, [
            ['category_id' => $category->id, 'amount' => '-10.0000'],
        ]);

        $this->getJson('/api/transactions?uncategorized=true')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $uncategorized->id);
        $this->getJson("/api/transactions?category_id={$category->id}&uncategorized=1")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('uncategorized');
    }

    public function test_transaction_resource_is_explicit_decimal_safe_and_reports_import_capabilities(): void
    {
        $this->actingAsAdmin();
        $account = Account::factory()->create([
            'account_number' => 'PRIVATE-123',
            'name' => 'Clinic Chequing',
            'type' => 'chequing',
            'currency' => 'CAD',
        ]);
        $parent = Category::factory()->create(['name' => 'Expenses']);
        $category = Category::factory()->create(['parent_id' => $parent->id, 'name' => 'Supplies']);
        $tenantId = Tenant::current()->id;
        $manual = $this->transaction($account, '2026-05-01', [
            'amount' => '-12.3400',
            'category_id' => $category->id,
            'notes' => 'Public note',
        ]);
        app(TransactionSplitService::class)->replace($manual, [
            ['category_id' => $category->id, 'amount' => '-12.3400', 'description' => 'Frames'],
        ]);
        $imported = $this->transaction($account, '2026-05-02', [
            'status' => TransactionStatus::Posted,
            'origin' => TransactionOrigin::Csv,
            'posted_at' => '2026-05-02 12:00:00',
        ]);
        $this->linkImport($imported);

        $response = $this->getJson('/api/transactions')->assertOk();
        $items = collect($response->json('data'))->keyBy('id');
        $manualJson = $items[$manual->id];
        $importedJson = $items[$imported->id];

        $this->assertSame([
            'id', 'account_id', 'account', 'transaction_date', 'amount', 'description',
            'notes', 'status', 'origin', 'posted_at', 'category_id', 'category',
            'splits', 'is_import_linked', 'bank_fields_editable', 'deletable',
        ], array_keys($manualJson));
        $this->assertSame('-12.3400', $manualJson['amount']);
        $this->assertSame(['id', 'name', 'type', 'currency'], array_keys($manualJson['account']));
        $this->assertSame($category->id, $manualJson['category_id']);
        $this->assertSame($parent->id, $manualJson['category']['parent']['id']);
        $this->assertSame('-12.3400', $manualJson['splits'][0]['amount']);
        $this->assertSame(
            ['id', 'category_id', 'amount', 'description', 'category'],
            array_keys($manualJson['splits'][0])
        );
        $this->assertFalse($manualJson['is_import_linked']);
        $this->assertTrue($manualJson['bank_fields_editable']);
        $this->assertTrue($manualJson['deletable']);
        $this->assertTrue($importedJson['is_import_linked']);
        $this->assertSame('2026-05-02T12:00:00.000000Z', $importedJson['posted_at']);
        $this->assertFalse($importedJson['bank_fields_editable']);
        $this->assertFalse($importedJson['deletable']);
        $response->assertJsonMissing(['tenant_id' => $tenantId])
            ->assertJsonMissing(['account_number' => 'PRIVATE-123']);
        foreach (['fingerprint', 'raw_payload', 'normalized_payload', 'imported_movement_id'] as $privateField) {
            $this->assertStringNotContainsString($privateField, $response->getContent());
        }
    }

    public function test_store_is_manual_pending_atomic_and_returns_valid_splits(): void
    {
        $this->actingAsAdmin();
        $account = Account::factory()->create();
        $category = Category::factory()->create();
        $payload = [
            'account_id' => $account->id,
            'transaction_date' => '2026-06-01',
            'description' => 'Manual purchase',
            'notes' => 'Needs receipt',
            'amount' => '-45.6700',
            'splits' => [['category_id' => $category->id, 'amount' => '-45.6700']],
        ];

        $id = $this->postJson('/api/transactions', $payload)
            ->assertCreated()
            ->assertJsonPath('data.origin', 'manual')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', '-45.6700')
            ->assertJsonCount(1, 'data.splits')
            ->json('data.id');
        $this->assertDatabaseHas('transactions', ['id' => $id, 'origin' => 'manual', 'status' => 'pending']);

        $this->postJson('/api/transactions', [...$payload, 'description' => 'Invalid total', 'splits' => [
            ['category_id' => $category->id, 'amount' => '-45.6600'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('splits');
        $this->assertDatabaseMissing('transactions', ['description' => 'Invalid total']);
        $this->postJson('/api/transactions', [...$payload, 'origin' => 'system'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('origin');
    }

    public function test_update_allows_manual_bank_fields_and_import_enrichment_but_protects_import_bank_fields(): void
    {
        $this->actingAsAdmin();
        $account = Account::factory()->create();
        $otherAccount = Account::factory()->create();
        $category = Category::factory()->create();
        $manual = $this->transaction($account, '2026-07-01');

        $this->patchJson("/api/transactions/{$manual->id}", [
            'account_id' => $otherAccount->id,
            'transaction_date' => '2026-07-02',
            'amount' => '-15.0000',
            'description' => 'Updated manual',
            'splits' => [['category_id' => $category->id, 'amount' => '-15.0000']],
        ])->assertOk()
            ->assertJsonPath('data.account_id', $otherAccount->id)
            ->assertJsonPath('data.splits.0.amount', '-15.0000');

        Tenant::query()->where('slug', 'personal')->firstOrFail()->makeCurrent();
        $imported = $this->transaction($account, '2026-07-03', [
            'amount' => '-20.0000',
            'status' => TransactionStatus::Posted,
            'origin' => TransactionOrigin::Csv,
        ]);
        $this->linkImport($imported);
        $this->patchJson("/api/transactions/{$imported->id}", [
            'account_id' => $account->id,
            'transaction_date' => '2026-07-03',
            'amount' => '-20.0000',
            'status' => 'posted',
            'category_id' => $category->id,
            'description' => 'Enriched import',
            'notes' => 'Reviewed',
            'splits' => [['category_id' => $category->id, 'amount' => '-20.0000']],
        ])->assertOk()
            ->assertJsonPath('data.description', 'Enriched import')
            ->assertJsonPath('data.notes', 'Reviewed');

        foreach ([
            ['account_id' => $otherAccount->id],
            ['transaction_date' => '2026-07-04'],
            ['amount' => '-20.0001'],
            ['status' => 'pending'],
        ] as $change) {
            $this->patchJson("/api/transactions/{$imported->id}", $change)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('transaction');
        }
    }

    public function test_delete_allows_manual_transactions_and_rejects_import_linked_transactions(): void
    {
        $this->actingAsAdmin();
        $account = Account::factory()->create();
        $manual = $this->transaction($account, '2026-08-01');
        $imported = $this->transaction($account, '2026-08-02', [
            'status' => TransactionStatus::Posted,
            'origin' => TransactionOrigin::Csv,
        ]);
        $this->linkImport($imported);

        $this->deleteJson("/api/transactions/{$manual->id}")->assertNoContent();
        $this->assertSoftDeleted($manual);
        $this->deleteJson("/api/transactions/{$imported->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('transaction');
    }

    public function test_filter_ids_and_route_binding_cannot_cross_tenants(): void
    {
        $this->actingAsAdmin();
        $personal = Tenant::query()->where('slug', 'personal')->firstOrFail();
        $clinic = Tenant::query()->where('slug', 'clinic')->firstOrFail();
        [$clinicAccount, $clinicCategory, $clinicTransaction] = $clinic->execute(function (): array {
            $account = Account::factory()->create();
            $category = Category::factory()->create();

            return [$account, $category, $this->transaction($account, '2026-08-03')];
        });
        $personal->makeCurrent();

        $this->getJson("/api/transactions?account_id={$clinicAccount->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('account_id');
        $this->getJson("/api/transactions?category_id={$clinicCategory->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->getJson("/api/transactions/{$clinicTransaction->id}")->assertNotFound();
        $this->patchJson("/api/transactions/{$clinicTransaction->id}", ['notes' => 'Cross tenant'])->assertNotFound();
        $this->deleteJson("/api/transactions/{$clinicTransaction->id}")->assertNotFound();
    }

    public function test_split_categories_cannot_cross_tenants_on_create_or_update(): void
    {
        $this->actingAsAdmin();
        $personal = Tenant::query()->where('slug', 'personal')->firstOrFail();
        $clinic = Tenant::query()->where('slug', 'clinic')->firstOrFail();
        $account = Account::factory()->create();
        $transaction = $this->transaction($account, '2026-08-04');
        $clinicCategory = $clinic->execute(fn () => Category::factory()->create());
        $personal->makeCurrent();
        $split = [['category_id' => $clinicCategory->id, 'amount' => '-10.0000']];

        $this->postJson('/api/transactions', [
            'account_id' => $account->id,
            'transaction_date' => '2026-08-04',
            'description' => 'Cross-tenant split create',
            'amount' => '-10.0000',
            'splits' => $split,
        ])->assertUnprocessable()->assertJsonValidationErrors('splits.0.category_id');

        $this->patchJson("/api/transactions/{$transaction->id}", ['splits' => $split])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('splits.0.category_id');
    }

    public function test_index_eager_loads_its_contract_with_a_bounded_query_count(): void
    {
        $this->actingAsAdmin();
        $account = Account::factory()->create();
        $category = Category::factory()->create();
        foreach (range(1, 25) as $index) {
            $transaction = $this->transaction($account, '2026-08-10', [
                'description' => "Transaction {$index}",
                'amount' => '-10.0000',
                'category_id' => $category->id,
            ]);
            app(TransactionSplitService::class)->replace($transaction, [
                ['category_id' => $category->id, 'amount' => '-10.0000'],
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/transactions?per_page=25')->assertOk()->assertJsonCount(25, 'data');
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(12, $queryCount, "Expected a bounded query count, got {$queryCount}.");
    }

    public function test_category_index_is_flat_and_detail_returns_public_children(): void
    {
        $this->actingAsAdmin();
        $parent = Category::factory()->create(['name' => 'Clinic costs']);
        $child = Category::factory()->create(['parent_id' => $parent->id, 'name' => 'Medical supplies']);

        $items = collect($this->getJson('/api/categories')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(['id', 'name', 'type', 'level', 'parent'], array_keys($items[$child->id]));
        $this->assertSame($parent->id, $items[$child->id]['parent']['id']);
        $this->assertSame(2, $items[$child->id]['level']);
        $this->assertArrayNotHasKey('tenant_id', $items[$child->id]);
        $this->assertArrayNotHasKey('children', $items[$parent->id]);

        $detail = $this->getJson("/api/categories/{$parent->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.children')
            ->assertJsonPath('data.children.0.id', $child->id)
            ->json('data');
        $this->assertSame(['id', 'name', 'type', 'level'], array_keys($detail['children'][0]));
        $this->assertStringNotContainsString('tenant_id', json_encode($detail, JSON_THROW_ON_ERROR));
    }

    private function transaction(Account $account, string $date, array $attributes = []): Transaction
    {
        return Transaction::factory()->create([
            'account_id' => $account->id,
            'transaction_date' => $date,
            'description' => 'Transaction',
            'amount' => '-10.0000',
            'status' => TransactionStatus::Pending,
            'origin' => TransactionOrigin::Manual,
            ...$attributes,
        ]);
    }

    private function linkImport(Transaction $transaction): void
    {
        ImportedMovement::query()->create([
            'account_id' => $transaction->account_id,
            'transaction_id' => $transaction->id,
            'source_name' => 'rbc',
            'fingerprint' => hash('sha256', "transaction-{$transaction->id}"),
            'occurrence' => 1,
        ]);
    }
}
