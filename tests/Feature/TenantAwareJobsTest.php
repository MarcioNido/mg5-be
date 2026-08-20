<?php

namespace Tests\Feature;

use App\Jobs\ProcessAllRules;
use App\Jobs\ProcessRule;
use App\Listeners\ProcessFileUploadedListener;
use App\Listeners\RecalculateBalancesListener;
use App\Models\Account;
use App\Models\Category;
use App\Models\Rule;
use App\Models\Tenant;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Multitenancy\Jobs\TenantAware;
use Tests\TestCase;

class TenantAwareJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_rule_jobs_only_process_the_dispatching_tenant(): void
    {
        $personal = $this->tenant('personal');
        $clinic = $this->tenant('clinic');

        [$rule, $personalTransaction] = $personal->execute(
            fn (): array => $this->ruleAndTransaction('PERSONAL-ACCOUNT')
        );
        [, $clinicTransaction] = $clinic->execute(
            fn (): array => $this->ruleAndTransaction('CLINIC-ACCOUNT')
        );

        $personal->execute(fn () => ProcessAllRules::dispatchSync());

        $personal->execute(function () use ($personalTransaction, $rule): void {
            $this->assertSame($rule->category_id, $personalTransaction->fresh()->category_id);
        });
        $clinic->execute(function () use ($clinicTransaction): void {
            $this->assertNull($clinicTransaction->fresh()->category_id);
        });
        $personal->execute(fn () => ProcessRule::dispatchSync($rule));
        $clinic->execute(function () use ($clinicTransaction): void {
            $this->assertNull($clinicTransaction->fresh()->category_id);
        });
    }

    public function test_all_financial_queue_handlers_are_explicitly_tenant_aware(): void
    {
        foreach ([
            ProcessAllRules::class,
            ProcessRule::class,
            ProcessFileUploadedListener::class,
            RecalculateBalancesListener::class,
        ] as $class) {
            $this->assertContains(TenantAware::class, class_implements($class));
        }
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::query()->where('slug', $slug)->firstOrFail();
    }

    private function ruleAndTransaction(string $accountNumber): array
    {
        Account::factory()->create([
            'account_number' => $accountNumber,
            'name' => $accountNumber,
            'type' => 'debit',
        ]);
        $category = Category::factory()->create();
        $rule = Rule::query()->create([
            'content' => '%TENANT MATCH%',
            'account_number' => $accountNumber,
            'category_id' => $category->id,
        ]);
        $transaction = Transaction::query()->create([
            'account_number' => $accountNumber,
            'transaction_date' => '2026-08-20',
            'description' => 'TENANT MATCH',
            'amount' => -10,
            'category_id' => null,
        ]);

        return [$rule, $transaction];
    }
}
