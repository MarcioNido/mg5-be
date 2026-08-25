<?php

namespace Tests\Feature;

use App\Enums\TransactionOrigin;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Category;
use App\Models\Reconciliation;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use App\Services\DashboardSummaryService;
use App\Services\Money;
use App\Services\ReconciliationService;
use App\Services\TransactionSplitService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

class Phase61ManagementDashboardApiTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00', 'America/Toronto'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_request_requires_authentication_membership_and_valid_tenant_selection(): void
    {
        $this->getJson('/api/dashboard/summary')->assertUnauthorized();

        $outsider = User::factory()->create();
        $this->actingAs($outsider)->withHeader('X-Tenant-Slug', 'clinic')
            ->getJson('/api/dashboard/summary')->assertForbidden();

        $this->actingAsAdmin();
        $this->withHeader('X-Tenant-Slug', 'unknown')
            ->getJson('/api/dashboard/summary')->assertNotFound();
        $this->withHeader('X-Tenant-Slug', '')
            ->getJson('/api/dashboard/summary')->assertNotFound();
    }

    public function test_month_defaults_in_toronto_rejects_future_and_is_strict(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/dashboard/summary')->assertOk()
            ->assertJsonPath('data.period.month', '2026-08')
            ->assertJsonPath('data.period.start_date', '2026-08-01')
            ->assertJsonPath('data.period.end_date', '2026-08-31')
            ->assertJsonPath('data.as_of_date', '2026-08-25');

        $this->getJson('/api/dashboard/summary?month=2024-02')->assertOk()
            ->assertJsonPath('data.period.end_date', '2024-02-29');

        foreach (['0000-01', '2026-2', '2026-00', '2026-13', '2026-02-01', '2026-02-01T00:00:00Z', 'anything', '2026-09'] as $month) {
            $this->getJson('/api/dashboard/summary?month='.urlencode($month))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('month');
        }
    }

    public function test_empty_state_has_the_complete_explicit_contract_and_exact_money_strings(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/dashboard/summary?month=2026-08')->assertOk();

        $response->assertExactJson(['data' => [
            'period' => [
                'month' => '2026-08',
                'start_date' => '2026-08-01',
                'end_date' => '2026-08-31',
            ],
            'as_of_date' => '2026-08-25',
            'accounts' => [],
            'account_totals_by_currency' => [],
            'period_activity' => [
                'posted_transactions_count' => 0,
                'by_currency' => [],
            ],
            'workflow' => [
                'pending_transactions_count' => 0,
                'uncategorized_posted_count' => 0,
                'uncategorized_pending_count' => 0,
                'accounts_needing_attention_count' => 0,
            ],
        ]]);
    }

    public function test_balances_follow_opening_checkpoint_as_of_date_and_keep_currencies_separate(): void
    {
        $this->actingAsAdmin();
        $cad = Account::factory()->create([
            'name' => 'Zulu CAD',
            'currency' => 'CAD',
            'opening_balance' => '99999999.9999',
            'opening_balance_date' => '2026-08-10',
        ]);
        $usd = Account::factory()->create([
            'name' => 'alpha USD',
            'currency' => 'USD',
            'opening_balance' => '-20.0001',
        ]);
        $this->transaction($cad, '500.0000', TransactionStatus::Posted, '2026-08-10');
        $this->transaction($cad, '-0.0001', TransactionStatus::Posted, '2026-08-11');
        $this->transaction($cad, '100.0000', TransactionStatus::Pending, '2026-08-12');
        $this->transaction($cad, '50.0000', TransactionStatus::Posted, '2026-08-26');
        $deleted = $this->transaction($cad, '75.0000', TransactionStatus::Posted, '2026-08-20');
        $deleted->delete();
        $this->transaction($usd, '-4.9999', TransactionStatus::Posted, '2026-01-01');

        $response = $this->getJson('/api/dashboard/summary?month=2025-01')->assertOk();

        $response->assertJsonPath('data.accounts.0.id', $usd->id)
            ->assertJsonPath('data.accounts.0.current_balance', '-25.0000')
            ->assertJsonPath('data.accounts.1.id', $cad->id)
            ->assertJsonPath('data.accounts.1.current_balance', '99999999.9998')
            ->assertJsonPath('data.accounts.1.last_posted_transaction_date', '2026-08-11')
            ->assertJsonPath('data.account_totals_by_currency.0.currency', 'CAD')
            ->assertJsonPath('data.account_totals_by_currency.0.amount', '99999999.9998')
            ->assertJsonPath('data.account_totals_by_currency.1.currency', 'USD')
            ->assertJsonPath('data.account_totals_by_currency.1.amount', '-25.0000');
    }

    public function test_reconciliation_statuses_use_current_exact_validity_and_attention_matches_accounts(): void
    {
        $this->actingAsAdmin();
        $never = Account::factory()->create(['name' => 'A never']);
        $upToDate = Account::factory()->create(['name' => 'B up']);
        $activity = Account::factory()->create(['name' => 'C activity']);
        $invalid = Account::factory()->create(['name' => 'D invalid']);
        $recalculated = Account::factory()->create(['name' => 'E recalculated']);
        $service = app(ReconciliationService::class);

        $validUp = $service->reconcile($upToDate, '2026-08-20', '0');
        $validActivity = $service->reconcile($activity, '2026-08-10', '0');
        $this->transaction($activity, '1.0000', TransactionStatus::Posted, '2026-08-11');
        $validOld = $service->reconcile($invalid, '2026-07-31', '0');
        $newInvalid = $service->reconcile($invalid, '2026-08-20', '1');
        $becameInvalid = $service->reconcile($recalculated, '2026-08-15', '0');
        $this->transaction($recalculated, '2.0000', TransactionStatus::Posted, '2026-08-10');

        $response = $this->getJson('/api/dashboard/summary')->assertOk();
        $accounts = collect($response->json('data.accounts'))->keyBy('id');

        $this->assertSame('never_reconciled', $accounts[$never->id]['reconciliation']['status']);
        $this->assertNull($accounts[$never->id]['reconciliation']['latest_valid']);
        $this->assertNull($accounts[$never->id]['reconciliation']['latest_attempt']);
        $this->assertSame('up_to_date', $accounts[$upToDate->id]['reconciliation']['status']);
        $this->assertFalse($accounts[$upToDate->id]['reconciliation']['needs_attention']);
        $this->assertSame($validUp->reconciled_at->toISOString(), $accounts[$upToDate->id]['reconciliation']['latest_valid']['reconciled_at']);
        $this->assertSame('activity_after_reconciliation', $accounts[$activity->id]['reconciliation']['status']);
        $this->assertSame('2026-08-10', $accounts[$activity->id]['reconciliation']['latest_valid']['statement_date']);
        $this->assertSame('latest_attempt_invalid', $accounts[$invalid->id]['reconciliation']['status']);
        $this->assertSame('2026-07-31', $accounts[$invalid->id]['reconciliation']['latest_valid']['statement_date']);
        $this->assertSame('2026-08-20', $accounts[$invalid->id]['reconciliation']['latest_attempt']['statement_date']);
        $this->assertFalse($accounts[$invalid->id]['reconciliation']['latest_attempt']['is_valid']);
        $this->assertSame('latest_attempt_invalid', $accounts[$recalculated->id]['reconciliation']['status']);
        $this->assertNull($accounts[$recalculated->id]['reconciliation']['latest_valid']);
        $this->assertFalse($accounts[$recalculated->id]['reconciliation']['latest_attempt']['is_valid']);
        $this->assertSame(4, $response->json('data.workflow.accounts_needing_attention_count'));

        $this->assertTrue($validActivity->fresh()->is_valid);
        $this->assertTrue($validOld->fresh()->is_valid);
        $this->assertFalse($newInvalid->fresh()->is_valid);
        $this->assertFalse($becameInvalid->fresh()->is_valid);
    }

    public function test_period_activity_uses_category_types_rolls_up_roots_and_never_double_counts_splits(): void
    {
        $this->actingAsAdmin();
        $account = Account::factory()->create();
        $expenseRoot = Category::factory()->create(['name' => 'alpha root', 'type' => 'expense']);
        $incomeChild = Category::factory()->create([
            'parent_id' => $expenseRoot->id, 'name' => 'Independent income', 'type' => 'income',
        ]);
        $expenseDetail = Category::factory()->create([
            'parent_id' => $incomeChild->id, 'name' => 'Expense detail', 'type' => 'expense',
        ]);
        $incomeRoot = Category::factory()->create(['name' => 'Zulu income', 'type' => 'income']);
        $transferRoot = Category::factory()->create(['name' => 'Moves', 'type' => 'transfer']);

        $this->transaction($account, '100.0000', TransactionStatus::Posted, '2026-08-01', $incomeRoot);
        $this->transaction($account, '-30.0000', TransactionStatus::Posted, '2026-08-31', $expenseDetail);
        $this->transaction($account, '5.0000', TransactionStatus::Posted, '2026-08-15', $expenseDetail);
        $this->transaction($account, '-9.0000', TransactionStatus::Posted, '2026-08-16', $transferRoot);
        $this->transaction($account, '2.0000', TransactionStatus::Posted, '2026-08-17');
        $split = $this->transaction($account, '-60.0000', TransactionStatus::Posted, '2026-08-18', $incomeRoot);
        app(TransactionSplitService::class)->replace($split, [
            ['category_id' => $incomeChild->id, 'amount' => '-10.0000'],
            ['category_id' => $transferRoot->id, 'amount' => '-50.0000'],
        ]);
        $this->transaction($account, '999.0000', TransactionStatus::Pending, '2026-08-20');
        $this->transaction($account, '1.0000', TransactionStatus::Posted, '2026-07-31', $incomeRoot);
        $this->transaction($account, '1.0000', TransactionStatus::Posted, '2026-09-01', $incomeRoot);

        $response = $this->getJson('/api/dashboard/summary?month=2026-08')->assertOk();
        $activity = $response->json('data.period_activity');
        $cadActivity = $activity['by_currency'][0];

        $this->assertSame(6, $activity['posted_transactions_count']);
        $this->assertSame('CAD', $cadActivity['currency']);
        $this->assertSame(6, $cadActivity['posted_transactions_count']);
        $this->assertSame([
            'income' => '90.0000',
            'expense' => '-25.0000',
            'transfer' => '-59.0000',
        ], $cadActivity['amounts_by_type']);
        $this->assertSame('2.0000', $cadActivity['uncategorized_amount']);
        $this->assertSame('8.0000', $cadActivity['confirmed_net_change']);
        $this->assertSame(
            [$expenseRoot->id, $incomeRoot->id, $transferRoot->id],
            collect($cadActivity['groups'])->pluck('category.id')->all()
        );
        $expenseGroup = collect($cadActivity['groups'])->firstWhere('category.id', $expenseRoot->id);
        $this->assertSame('-10.0000', $expenseGroup['amounts_by_type']['income']);
        $this->assertSame('-25.0000', $expenseGroup['amounts_by_type']['expense']);
        $this->assertSame('-35.0000', $expenseGroup['net_change']);
        $this->assertSame(
            Money::units($cadActivity['confirmed_net_change']),
            collect($cadActivity['groups'])->sum(fn (array $group): int => Money::units($group['net_change']))
                + Money::units($cadActivity['uncategorized_amount'])
        );
    }

    public function test_period_activity_separates_currency_buckets_and_reconciles_each_one_exactly(): void
    {
        $this->actingAsAdmin();
        $cad = Account::factory()->create(['currency' => 'CAD']);
        $usd = Account::factory()->create(['currency' => 'USD']);
        $root = Category::factory()->create(['name' => 'Shared management group', 'type' => 'expense']);
        $income = Category::factory()->create([
            'parent_id' => $root->id, 'name' => 'Independent income', 'type' => 'income',
        ]);
        $transfer = Category::factory()->create([
            'parent_id' => $root->id, 'name' => 'Independent transfer', 'type' => 'transfer',
        ]);

        $this->transaction($cad, '100.0000', TransactionStatus::Posted, '2026-08-01', $income);
        $this->transaction($cad, '-10.0000', TransactionStatus::Posted, '2026-08-02');
        $cadSplit = $this->transaction($cad, '-30.0000', TransactionStatus::Posted, '2026-08-03', $income);
        app(TransactionSplitService::class)->replace($cadSplit, [
            ['category_id' => $root->id, 'amount' => '-20.0000'],
            ['category_id' => $transfer->id, 'amount' => '-10.0000'],
        ]);

        $this->transaction($usd, '7.0000', TransactionStatus::Posted, '2026-08-04', $income);
        $this->transaction($usd, '-2.0000', TransactionStatus::Posted, '2026-08-05');
        $usdSplit = $this->transaction($usd, '-4.0000', TransactionStatus::Posted, '2026-08-06', $income);
        app(TransactionSplitService::class)->replace($usdSplit, [
            ['category_id' => $root->id, 'amount' => '-3.0000'],
            ['category_id' => $income->id, 'amount' => '-1.0000'],
        ]);

        $deletedAccount = Account::factory()->create(['currency' => 'AUD']);
        $this->transaction($deletedAccount, '999.0000', TransactionStatus::Posted, '2026-08-07', $income);
        $deletedAccount->delete();
        $this->transaction($cad, '888.0000', TransactionStatus::Pending, '2026-08-08', $income);
        $deletedTransaction = $this->transaction($usd, '777.0000', TransactionStatus::Posted, '2026-08-09', $income);
        $deletedTransaction->delete();

        $response = $this->getJson('/api/dashboard/summary?month=2026-08')->assertOk();
        $activity = $response->json('data.period_activity');
        $buckets = collect($activity['by_currency'])->keyBy('currency');

        $this->assertSame(['CAD', 'USD'], collect($activity['by_currency'])->pluck('currency')->all());
        $this->assertSame(6, $activity['posted_transactions_count']);
        $this->assertSame(6, $buckets->sum('posted_transactions_count'));
        foreach (['amounts_by_type', 'uncategorized_amount', 'confirmed_net_change', 'groups'] as $unsafeKey) {
            $this->assertArrayNotHasKey($unsafeKey, $activity);
        }

        $this->assertSame(3, $buckets['CAD']['posted_transactions_count']);
        $this->assertSame([
            'income' => '100.0000',
            'expense' => '-20.0000',
            'transfer' => '-10.0000',
        ], $buckets['CAD']['amounts_by_type']);
        $this->assertSame('-10.0000', $buckets['CAD']['uncategorized_amount']);
        $this->assertSame('60.0000', $buckets['CAD']['confirmed_net_change']);

        $this->assertSame(3, $buckets['USD']['posted_transactions_count']);
        $this->assertSame([
            'income' => '6.0000',
            'expense' => '-3.0000',
            'transfer' => '0.0000',
        ], $buckets['USD']['amounts_by_type']);
        $this->assertSame('-2.0000', $buckets['USD']['uncategorized_amount']);
        $this->assertSame('1.0000', $buckets['USD']['confirmed_net_change']);

        foreach (['CAD', 'USD'] as $currency) {
            $bucket = $buckets[$currency];
            $this->assertSame([$root->id], collect($bucket['groups'])->pluck('category.id')->all());
            $this->assertSame(
                Money::units($bucket['confirmed_net_change']),
                collect($bucket['groups'])->sum(fn (array $group): int => Money::units($group['net_change']))
                    + Money::units($bucket['uncategorized_amount'])
            );
        }

        $this->assertStringNotContainsString('exchange_rate', $response->getContent());
        $this->assertStringNotContainsString('converted', $response->getContent());
    }

    public function test_workflow_counts_are_tenant_wide_exclude_deleted_and_treat_splits_as_categorized(): void
    {
        $this->actingAsAdmin();
        $account = Account::factory()->create();
        $category = Category::factory()->create();
        $this->transaction($account, '1.0000', TransactionStatus::Pending, '2026-01-01');
        $this->transaction($account, '1.0000', TransactionStatus::Pending, '2026-01-02', $category);
        $this->transaction($account, '1.0000', TransactionStatus::Posted, '2026-01-03');
        $split = $this->transaction($account, '1.0000', TransactionStatus::Posted, '2026-01-04');
        app(TransactionSplitService::class)->replace($split, [[
            'category_id' => $category->id, 'amount' => '1.0000',
        ]]);
        $deleted = $this->transaction($account, '1.0000', TransactionStatus::Pending, '2026-01-05');
        $deleted->delete();

        $this->getJson('/api/dashboard/summary?month=2025-01')->assertOk()
            ->assertJsonPath('data.workflow.pending_transactions_count', 2)
            ->assertJsonPath('data.workflow.uncategorized_pending_count', 1)
            ->assertJsonPath('data.workflow.uncategorized_posted_count', 1);
    }

    public function test_tenants_are_isolated_private_fields_never_serialize_and_models_fail_closed(): void
    {
        $this->actingAsAdmin();
        $personalAccount = Account::factory()->create([
            'name' => 'Personal account', 'account_number' => 'PERSONAL-PRIVATE', 'opening_balance' => '10.0000',
        ]);
        $personalCategory = Category::factory()->create(['name' => 'Personal category']);
        $this->transaction($personalAccount, '1.0000', TransactionStatus::Posted, '2026-08-01', $personalCategory);
        $clinic = Tenant::query()->where('slug', 'clinic')->firstOrFail();
        $clinic->execute(function (): void {
            $account = Account::factory()->create([
                'name' => 'Clinic account', 'account_number' => 'CLINIC-PRIVATE', 'opening_balance' => '20.0000',
            ]);
            $category = Category::factory()->create(['name' => 'Clinic category']);
            $this->transaction($account, '2.0000', TransactionStatus::Posted, '2026-08-01', $category);
            app(ReconciliationService::class)->reconcile($account, '2026-08-01', '22.0000');
        });

        $personal = $this->withHeader('X-Tenant-Slug', 'personal')
            ->getJson('/api/dashboard/summary')->assertOk();
        $personal->assertJsonPath('data.accounts.0.name', 'Personal account')
            ->assertJsonPath('data.period_activity.by_currency.0.confirmed_net_change', '1.0000')
            ->assertJsonMissing(['name' => 'Clinic account'])
            ->assertJsonMissing(['name' => 'Clinic category']);
        $clinicResponse = $this->withHeader('X-Tenant-Slug', 'clinic')
            ->getJson('/api/dashboard/summary')->assertOk();
        $clinicResponse->assertJsonPath('data.accounts.0.name', 'Clinic account')
            ->assertJsonPath('data.period_activity.by_currency.0.confirmed_net_change', '2.0000')
            ->assertJsonMissing(['name' => 'Personal account'])
            ->assertJsonMissing(['name' => 'Personal category']);

        foreach ([$personal, $clinicResponse] as $response) {
            foreach (['tenant_id', 'account_number', 'deleted_at', 'PERSONAL-PRIVATE', 'CLINIC-PRIVATE'] as $private) {
                $this->assertStringNotContainsString($private, $response->getContent());
            }
        }

        Tenant::forgetCurrent();
        $this->assertSame(0, Account::query()->count());
        $this->assertSame(0, Category::query()->count());
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(0, TransactionSplit::query()->count());
        $this->assertSame(0, Reconciliation::query()->count());
        $summary = app(DashboardSummaryService::class)->summarize('2026-08');
        $this->assertSame([], $summary['accounts']);
        $this->assertSame([], $summary['period_activity']['by_currency']);
    }

    public function test_query_count_is_bounded_as_dashboard_data_grows(): void
    {
        $this->actingAsAdmin();
        $this->dashboardFixture(1);
        $smallCount = $this->dashboardQueryCount();

        $this->dashboardFixture(12);
        $largeCount = $this->dashboardQueryCount();

        $this->assertSame($smallCount, $largeCount, "Dashboard query count grew from {$smallCount} to {$largeCount}.");
        $this->assertLessThanOrEqual(20, $largeCount, "Expected at most 20 queries, got {$largeCount}.");
    }

    private function dashboardFixture(int $count): void
    {
        Tenant::query()->where('slug', 'personal')->firstOrFail()->makeCurrent();

        foreach (range(1, $count) as $index) {
            $account = Account::factory()->create([
                'name' => "Performance {$count}-{$index}",
                'currency' => $index % 2 === 0 ? 'USD' : 'CAD',
            ]);
            $root = Category::factory()->create(['name' => "Root {$count}-{$index}", 'type' => 'expense']);
            $child = Category::factory()->create(['parent_id' => $root->id, 'type' => 'income']);
            $this->transaction($account, '1.0000', TransactionStatus::Posted, '2026-08-09', $child);
            $this->transaction($account, '-1.0000', TransactionStatus::Posted, '2026-08-11');
            $transaction = $this->transaction($account, '10.0000', TransactionStatus::Posted, '2026-08-10', $child);
            app(TransactionSplitService::class)->replace($transaction, [[
                'category_id' => $child->id, 'amount' => '10.0000',
            ]]);
            app(ReconciliationService::class)->reconcile($account, '2026-08-10', '10.0000');
        }
    }

    private function dashboardQueryCount(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/dashboard/summary')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function transaction(
        Account $account,
        string $amount,
        TransactionStatus $status,
        string $date,
        ?Category $category = null
    ): Transaction {
        return Transaction::query()->create([
            'account_id' => $account->id,
            'category_id' => $category?->id,
            'transaction_date' => $date,
            'description' => 'Dashboard fixture',
            'amount' => $amount,
            'status' => $status,
            'origin' => TransactionOrigin::Manual,
            'posted_at' => $status === TransactionStatus::Posted ? now() : null,
        ]);
    }
}
