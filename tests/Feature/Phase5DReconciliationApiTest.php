<?php

namespace Tests\Feature;

use App\Enums\TransactionOrigin;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Category;
use App\Models\Reconciliation;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ReconciliationService;
use App\Services\TransactionSplitService;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

class Phase5DReconciliationApiTest extends ApiTestCase
{
    public function test_endpoints_require_authentication_and_valid_tenant_selection(): void
    {
        $account = Account::factory()->create();
        $base = "/api/accounts/{$account->id}/reconciliations";

        $this->getJson($base)->assertUnauthorized();
        $this->getJson("{$base}/preview?statement_date=2026-01-31")->assertUnauthorized();
        $this->postJson($base, [
            'statement_date' => '2026-01-31',
            'entered_bank_balance' => '0',
        ])->assertUnauthorized();
        $this->getJson("{$base}/latest")->assertUnauthorized();

        $this->actingAsAdmin();
        $this->withHeader('X-Tenant-Slug', '')->getJson($base)->assertNotFound();
        $this->withHeader('X-Tenant-Slug', 'unknown')->getJson($base)->assertNotFound();
    }

    public function test_membership_and_tenant_scoped_account_binding_are_enforced(): void
    {
        $personal = Tenant::query()->where('slug', 'personal')->firstOrFail();
        $clinic = Tenant::query()->where('slug', 'clinic')->firstOrFail();
        $personalAccount = Account::factory()->create();
        $clinicAccount = $clinic->execute(fn () => Account::factory()->create());
        $personal->makeCurrent();
        $nonMember = User::factory()->create();

        $this->actingAs($nonMember)->withHeader('X-Tenant-Slug', 'clinic')
            ->getJson("/api/accounts/{$clinicAccount->id}/reconciliations")
            ->assertForbidden();

        $this->actingAsAdmin()->withHeader('X-Tenant-Slug', 'personal')
            ->getJson("/api/accounts/{$clinicAccount->id}/reconciliations")
            ->assertNotFound();
        $this->withHeader('X-Tenant-Slug', 'clinic')
            ->getJson("/api/accounts/{$personalAccount->id}/reconciliations")
            ->assertNotFound();
    }

    public function test_preview_validates_a_strict_civil_date_and_never_persists(): void
    {
        $this->actingAsAdmin();
        $account = Account::factory()->create();
        $url = "/api/accounts/{$account->id}/reconciliations/preview";

        $this->getJson($url)->assertUnprocessable()->assertJsonValidationErrors('statement_date');
        foreach (['2026-2-01', '02/01/2026', '2026-02-30', 'tomorrow'] as $date) {
            $this->getJson("{$url}?statement_date={$date}")
                ->assertUnprocessable()->assertJsonValidationErrors('statement_date');
        }

        $this->getJson("{$url}?statement_date=2026-02-28")
            ->assertOk()
            ->assertExactJson(['data' => [
                'statement_date' => '2026-02-28',
                'calculated_balance' => '0.0000',
            ]]);
        $this->assertDatabaseCount('reconciliations', 0);
    }

    public function test_preview_uses_opening_checkpoint_and_only_eligible_posted_transactions(): void
    {
        $this->actingAsAdmin();
        $account = Account::factory()->create([
            'opening_balance' => '100.1250',
            'opening_balance_date' => '2026-01-10',
        ]);
        $this->transaction($account, '50.0000', TransactionStatus::Posted, '2026-01-10');
        $this->transaction($account, '-10.1250', TransactionStatus::Posted, '2026-01-11');
        $this->transaction($account, '500.0000', TransactionStatus::Pending, '2026-01-12');
        $deleted = $this->transaction($account, '25.0000', TransactionStatus::Posted, '2026-01-13');
        $deleted->delete();
        $this->transaction($account, '30.0000', TransactionStatus::Posted, '2026-02-01');

        $this->getJson("/api/accounts/{$account->id}/reconciliations/preview?statement_date=2026-01-31")
            ->assertOk()
            ->assertJsonPath('data.calculated_balance', '90.0000');
    }

    public function test_history_is_paginated_ordered_and_has_an_explicit_safe_contract(): void
    {
        $this->actingAsAdmin();
        $account = Account::factory()->create(['account_number' => 'PRIVATE-123']);
        $service = app(ReconciliationService::class);
        $service->reconcile($account, '2026-01-01', '0');
        $service->reconcile($account, '2026-01-03', '2.5');
        $service->reconcile($account, '2026-01-02', '-3');

        $response = $this->getJson("/api/accounts/{$account->id}/reconciliations?per_page=2&page=1")
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('data.0.statement_date', '2026-01-03')
            ->assertJsonPath('data.0.entered_bank_balance', '2.5000')
            ->assertJsonPath('data.0.calculated_balance', '0.0000')
            ->assertJsonPath('data.0.difference', '2.5000')
            ->assertJsonPath('data.0.is_valid', false)
            ->assertJsonPath('data.0.reconciled_at', null)
            ->assertJsonPath('data.1.statement_date', '2026-01-02');

        $this->assertSame(
            ['id', 'statement_date', 'entered_bank_balance', 'calculated_balance', 'difference', 'is_valid', 'reconciled_at'],
            array_keys($response->json('data.0'))
        );
        $this->assertStringContainsString('per_page=2', $response->json('links.next'));
        foreach (['tenant_id', 'account_id', 'account_number', 'created_at', 'updated_at'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }

        $this->getJson("/api/accounts/{$account->id}/reconciliations?per_page=2&page=2")
            ->assertOk()->assertJsonPath('data.0.statement_date', '2026-01-01');
        $this->getJson("/api/accounts/{$account->id}/reconciliations?per_page=51")
            ->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson("/api/accounts/{$account->id}/reconciliations?page=0")
            ->assertUnprocessable()->assertJsonValidationErrors('page');
    }

    public function test_empty_history_and_history_query_count_are_bounded(): void
    {
        $this->actingAsAdmin();
        $account = Account::factory()->create();

        $this->getJson("/api/accounts/{$account->id}/reconciliations")
            ->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.per_page', 15);
        $this->personalTenant()->makeCurrent();
        foreach (range(1, 20) as $day) {
            app(ReconciliationService::class)->reconcile($account, "2026-03-{$day}", '0');
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson("/api/accounts/{$account->id}/reconciliations")
            ->assertOk()->assertJsonCount(15, 'data');
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(8, $queryCount, "Expected a bounded query count, got {$queryCount}.");
    }

    public function test_store_requires_string_money_creates_then_replaces_the_same_date(): void
    {
        $this->actingAsAdmin();
        $account = Account::factory()->create(['opening_balance' => '12.3400']);
        $url = "/api/accounts/{$account->id}/reconciliations";

        $this->postJson($url, [])->assertUnprocessable()
            ->assertJsonValidationErrors(['statement_date', 'entered_bank_balance']);
        $this->postJson($url, [
            'statement_date' => '2026-01-31', 'entered_bank_balance' => 12.34,
        ])->assertUnprocessable()->assertJsonValidationErrors('entered_bank_balance');
        $this->postJson($url, [
            'statement_date' => '2026-01-31', 'entered_bank_balance' => '12.34000',
        ])->assertUnprocessable()->assertJsonValidationErrors('entered_bank_balance');

        $created = $this->postJson($url, [
            'statement_date' => '2026-01-31', 'entered_bank_balance' => '12.34',
        ])->assertCreated()
            ->assertJsonPath('data.entered_bank_balance', '12.3400')
            ->assertJsonPath('data.difference', '0.0000')
            ->assertJsonPath('data.is_valid', true);
        $this->assertNotNull($created->json('data.reconciled_at'));

        $this->postJson($url, [
            'statement_date' => '2026-01-31', 'entered_bank_balance' => '10',
        ])->assertOk()
            ->assertJsonPath('data.id', $created->json('data.id'))
            ->assertJsonPath('data.entered_bank_balance', '10.0000')
            ->assertJsonPath('data.difference', '-2.3400')
            ->assertJsonPath('data.is_valid', false)
            ->assertJsonPath('data.reconciled_at', null);
        $this->personalTenant()->makeCurrent();
        $this->assertSame(1, Reconciliation::query()->count());
    }

    public function test_same_date_is_independent_per_account_and_positive_difference_is_exact(): void
    {
        $this->actingAsAdmin();
        $first = Account::factory()->create(['opening_balance' => '-1.0001']);
        $second = Account::factory()->create(['opening_balance' => '2.0002']);

        $this->postJson("/api/accounts/{$first->id}/reconciliations", [
            'statement_date' => '2026-01-31', 'entered_bank_balance' => '0',
        ])->assertCreated()->assertJsonPath('data.difference', '1.0001');
        $this->postJson("/api/accounts/{$second->id}/reconciliations", [
            'statement_date' => '2026-01-31', 'entered_bank_balance' => '2.0002',
        ])->assertCreated()->assertJsonPath('data.is_valid', true);

        $this->personalTenant()->makeCurrent();
        $this->assertSame(2, Reconciliation::query()->count());
    }

    public function test_latest_returns_null_or_the_most_recent_currently_valid_row(): void
    {
        $this->actingAsAdmin();
        $account = Account::factory()->create();
        $url = "/api/accounts/{$account->id}/reconciliations/latest";

        $this->getJson($url)->assertOk()->assertExactJson(['data' => null]);
        $this->personalTenant()->makeCurrent();
        $service = app(ReconciliationService::class);
        $older = $service->reconcile($account, '2026-01-31', '0');
        $february = $this->transaction($account, '1.0000', TransactionStatus::Posted, '2026-02-15');
        $newer = $service->reconcile($account, '2026-02-28', '1');

        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.id', $newer->id)
            ->assertJsonPath('data.statement_date', '2026-02-28');

        $this->personalTenant()->makeCurrent();
        $february->update(['amount' => '2.0000']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.id', $older->id);

        $this->personalTenant()->makeCurrent();
        $transaction = $this->transaction($account, '1.0000', TransactionStatus::Posted, '2026-01-15');
        $this->getJson($url)->assertOk()->assertExactJson(['data' => null]);
        $this->personalTenant()->makeCurrent();
        $transaction->update(['amount' => '0.0000']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.id', $older->id);
    }

    public function test_balance_affecting_transaction_mutations_recalculate_old_and_new_accounts(): void
    {
        $first = Account::factory()->create();
        $second = Account::factory()->create();
        $service = app(ReconciliationService::class);
        $firstReconciliation = $service->reconcile($first, '2026-06-30', '0');
        $secondReconciliation = $service->reconcile($second, '2026-06-30', '0');
        $transaction = $this->transaction($first, '10.0000', TransactionStatus::Pending, '2026-06-01');

        $this->assertTrue($firstReconciliation->fresh()->is_valid);
        $transaction->update(['status' => TransactionStatus::Posted]);
        $this->assertSame('10.0000', $firstReconciliation->fresh()->calculated_balance);
        $transaction->update(['amount' => '12.0000']);
        $this->assertSame('12.0000', $firstReconciliation->fresh()->calculated_balance);
        $transaction->update(['transaction_date' => '2026-07-01']);
        $this->assertTrue($firstReconciliation->fresh()->is_valid);
        $transaction->update(['transaction_date' => '2026-06-01', 'account_id' => $second->id]);
        $this->assertTrue($firstReconciliation->fresh()->is_valid);
        $this->assertSame('12.0000', $secondReconciliation->fresh()->calculated_balance);
        $transaction->update(['status' => TransactionStatus::Pending]);
        $this->assertTrue($secondReconciliation->fresh()->is_valid);
        $transaction->update(['status' => TransactionStatus::Posted]);
        $transaction->delete();
        $this->assertTrue($secondReconciliation->fresh()->is_valid);
    }

    public function test_later_and_non_balance_changes_do_not_affect_earlier_reconciliation(): void
    {
        $account = Account::factory()->create();
        $service = app(ReconciliationService::class);
        $reconciliation = $service->reconcile($account, '2026-06-30', '0');
        $originalTimestamp = $reconciliation->reconciled_at->toISOString();
        $later = $this->transaction($account, '10.0000', TransactionStatus::Posted, '2026-07-01');
        $category = Category::factory()->create();

        $later->update([
            'description' => 'Changed description',
            'notes' => 'Changed notes',
            'category_id' => $category->id,
        ]);
        app(TransactionSplitService::class)->replace($later, [[
            'category_id' => $category->id,
            'amount' => '10.0000',
        ]]);

        $this->assertTrue($reconciliation->fresh()->is_valid);
        $this->assertSame('0.0000', $reconciliation->fresh()->calculated_balance);
        $this->assertSame($originalTimestamp, $reconciliation->fresh()->reconciled_at->toISOString());
    }

    public function test_opening_checkpoint_changes_recalculate_all_rows_and_can_restore_validity(): void
    {
        $account = Account::factory()->create([
            'opening_balance' => '0.0000',
            'opening_balance_date' => '2026-01-01',
        ]);
        $this->transaction($account, '5.0000', TransactionStatus::Posted, '2026-01-15');
        $service = app(ReconciliationService::class);
        $january = $service->reconcile($account, '2026-01-31', '5');
        $february = $service->reconcile($account, '2026-02-28', '5');

        $account->update(['opening_balance' => '1.0000']);
        $this->assertFalse($january->fresh()->is_valid);
        $this->assertFalse($february->fresh()->is_valid);
        $account->update(['opening_balance' => '5.0000', 'opening_balance_date' => '2026-01-20']);
        $this->assertTrue($january->fresh()->is_valid);
        $this->assertTrue($february->fresh()->is_valid);
    }

    public function test_personal_and_clinic_histories_are_isolated_and_models_fail_closed(): void
    {
        $this->actingAsAdmin();
        $personal = Tenant::query()->where('slug', 'personal')->firstOrFail();
        $clinic = Tenant::query()->where('slug', 'clinic')->firstOrFail();
        $personalAccount = Account::factory()->create(['name' => 'Personal']);
        app(ReconciliationService::class)->reconcile($personalAccount, '2026-01-31', '0');
        $clinicAccount = $clinic->execute(function (): Account {
            $account = Account::factory()->create(['name' => 'Clinic']);
            app(ReconciliationService::class)->reconcile($account, '2026-02-28', '0');

            return $account;
        });
        $personal->makeCurrent();

        $this->withHeader('X-Tenant-Slug', 'personal')
            ->getJson("/api/accounts/{$personalAccount->id}/reconciliations")
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.statement_date', '2026-01-31');
        $this->withHeader('X-Tenant-Slug', 'clinic')
            ->getJson("/api/accounts/{$clinicAccount->id}/reconciliations")
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.statement_date', '2026-02-28');

        Tenant::forgetCurrent();
        $this->assertSame(0, Reconciliation::query()->count());
        $this->assertSame(0, Account::query()->count());
        $this->assertSame(0, Transaction::query()->count());
    }

    private function transaction(
        Account $account,
        string $amount,
        TransactionStatus $status,
        string $date
    ): Transaction {
        return Transaction::query()->create([
            'account_id' => $account->id,
            'transaction_date' => $date,
            'description' => 'Transaction',
            'amount' => $amount,
            'status' => $status,
            'origin' => TransactionOrigin::Manual,
            'posted_at' => $status === TransactionStatus::Posted ? now() : null,
        ]);
    }

    private function personalTenant(): Tenant
    {
        return Tenant::query()->where('slug', 'personal')->firstOrFail();
    }
}
