<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class MinimalTenancyIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_member_selects_an_isolated_tenant_by_slug_header(): void
    {
        $user = User::factory()->create();
        $personal = $this->tenant('personal');
        $clinic = $this->tenant('clinic');
        $user->tenants()->sync([$personal->id, $clinic->id]);

        $personalTransaction = $this->transactionFor(
            $personal,
            'PERSONAL-ACCOUNT',
            'Personal transaction'
        );
        $clinicTransaction = $this->transactionFor(
            $clinic,
            'CLINIC-ACCOUNT',
            'Clinic transaction'
        );

        $this->actingAs($user)
            ->withHeader('X-Tenant-Slug', 'clinic')
            ->getJson('/api/transactions')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonFragment(['description' => 'Clinic transaction'])
            ->assertJsonMissing(['description' => 'Personal transaction']);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Slug', 'personal')
            ->getJson('/api/transactions')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonFragment(['description' => 'Personal transaction'])
            ->assertJsonMissing(['description' => 'Clinic transaction']);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Slug', 'clinic')
            ->getJson("/api/transactions/{$personalTransaction->id}")
            ->assertNotFound();

        $this->actingAs($user)
            ->withHeader('X-Tenant-Slug', 'clinic')
            ->getJson("/api/transactions/{$clinicTransaction->id}")
            ->assertOk();
    }

    public function test_personal_is_the_compatible_default_and_membership_is_required(): void
    {
        $user = User::factory()->create();
        $personal = $this->tenant('personal');
        $clinic = $this->tenant('clinic');
        $this->transactionFor($personal, 'PERSONAL-ACCOUNT', 'Personal transaction');

        $this->actingAs($user)
            ->getJson('/api/transactions')
            ->assertOk()
            ->assertJsonFragment(['description' => 'Personal transaction']);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Slug', $clinic->slug)
            ->getJson('/api/transactions')
            ->assertForbidden();

        $this->actingAs($user)
            ->withHeader('X-Tenant-Slug', 'unknown')
            ->getJson('/api/transactions')
            ->assertNotFound();
    }

    public function test_tenant_scoped_validation_rejects_cross_tenant_relations(): void
    {
        $user = User::factory()->create();
        $personal = $this->tenant('personal');
        $clinic = $this->tenant('clinic');
        $user->tenants()->sync([$personal->id, $clinic->id]);

        [$account, $category] = $personal->execute(function (): array {
            return [
                Account::factory()->create([
                    'account_number' => 'PERSONAL-ACCOUNT',
                    'name' => 'Personal account',
                    'type' => 'debit',
                ]),
                Category::factory()->create(['name' => 'Personal category']),
            ];
        });

        $this->actingAs($user)
            ->withHeader('X-Tenant-Slug', 'clinic')
            ->postJson('/api/transactions', [
                'account' => ['account_number' => $account->account_number],
                'category' => ['id' => $category->id],
                'transaction_date' => '2026-08-20',
                'description' => 'Cross tenant attempt',
                'amount' => -10,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account.account_number', 'category.id']);
    }

    public function test_financial_models_fail_closed_without_a_current_tenant(): void
    {
        $personal = $this->tenant('personal');
        $this->transactionFor($personal, 'PERSONAL-ACCOUNT', 'Hidden without tenant');

        Tenant::forgetCurrent();

        $this->assertSame(0, Transaction::query()->count());

        $this->expectException(LogicException::class);
        Transaction::query()->create([
            'account_number' => 'PERSONAL-ACCOUNT',
            'transaction_date' => '2026-08-20',
            'description' => 'No tenant',
            'amount' => -10,
        ]);
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::query()->where('slug', $slug)->firstOrFail();
    }

    private function transactionFor(
        Tenant $tenant,
        string $accountNumber,
        string $description
    ): Transaction {
        return $tenant->execute(function () use ($accountNumber, $description): Transaction {
            Account::factory()->create([
                'account_number' => $accountNumber,
                'name' => $accountNumber,
                'type' => 'debit',
            ]);

            return Transaction::query()->create([
                'account_number' => $accountNumber,
                'transaction_date' => '2026-08-20',
                'description' => $description,
                'amount' => -10,
            ]);
        });
    }
}
