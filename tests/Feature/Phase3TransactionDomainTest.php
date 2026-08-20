<?php

namespace Tests\Feature;

use App\Enums\ImportRowStatus;
use App\Enums\ImportStatus;
use App\Enums\MatchSuggestionStatus;
use App\Enums\TransactionOrigin;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Category;
use App\Models\File;
use App\Models\ImportedMovement;
use App\Models\ImportRow;
use App\Models\MatchSuggestion;
use App\Models\Reconciliation;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use App\Services\ReconciliationService;
use App\Services\TransactionMatchingService;
use App\Services\TransactionSplitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Phase3TransactionDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_account_number_is_allowed_in_different_tenants(): void
    {
        $personal = Tenant::query()->where('slug', 'personal')->firstOrFail();
        $clinic = Tenant::query()->where('slug', 'clinic')->firstOrFail();

        $personal->execute(fn () => Account::factory()->create(['account_number' => 'SHARED-1234']));
        $clinic->execute(fn () => Account::factory()->create(['account_number' => 'SHARED-1234']));

        $this->assertSame(2, Account::withoutGlobalScope('tenant')->where('account_number', 'SHARED-1234')->count());
    }

    public function test_all_phase_three_tables_are_scoped_to_the_current_tenant(): void
    {
        $personal = Tenant::query()->where('slug', 'personal')->firstOrFail();
        $clinic = Tenant::query()->where('slug', 'clinic')->firstOrFail();
        $personal->execute(fn () => $this->createTenantGraph('personal'));
        $clinic->execute(fn () => $this->createTenantGraph('clinic'));

        foreach ([$personal, $clinic] as $tenant) {
            $tenant->execute(function (): void {
                $this->assertSame(1, Account::query()->where('name', 'like', 'Graph %')->count());
                $this->assertSame(1, Transaction::query()->where('description', 'Graph transaction')->count());
                $this->assertSame(1, File::query()->where('filename', 'like', 'graph-%')->count());
                $this->assertSame(1, ImportRow::query()->where('line_number', 99)->count());
                $this->assertSame(1, ImportedMovement::query()->count());
                $this->assertSame(1, TransactionSplit::query()->count());
                $this->assertSame(1, MatchSuggestion::query()->count());
                $this->assertSame(1, Reconciliation::query()->count());
            });
        }
    }

    public function test_pending_is_excluded_and_posted_is_included_in_confirmed_balance(): void
    {
        $account = Account::factory()->create([
            'opening_balance' => '100.0000',
            'opening_balance_date' => '2026-01-01',
        ]);
        $this->transaction($account, '-25.0000', TransactionStatus::Pending, '2026-01-05');
        $this->transaction($account, '10.5000', TransactionStatus::Posted, '2026-01-06');

        $this->assertSame('110.5000', app(ReconciliationService::class)->calculate($account, '2026-01-31'));
    }

    public function test_splits_must_close_exactly_and_do_not_change_reconciliation(): void
    {
        $account = Account::factory()->create();
        $first = Category::factory()->create();
        $second = Category::factory()->create();
        $transaction = $this->transaction($account, '-100.0000', TransactionStatus::Posted, '2026-02-01');
        $reconciliation = app(ReconciliationService::class)->reconcile($account, '2026-02-28', '-100.0000');

        app(TransactionSplitService::class)->replace($transaction, [
            ['category_id' => $first->id, 'amount' => '-60.0000'],
            ['category_id' => $second->id, 'amount' => '-40.0000'],
        ]);
        $transaction->update(['category_id' => $first->id]);

        $this->assertTrue($reconciliation->fresh()->is_valid);
        $this->expectException(ValidationException::class);
        app(TransactionSplitService::class)->replace($transaction, [
            ['category_id' => $first->id, 'amount' => '-99.9999'],
        ]);
    }

    public function test_invalid_api_splits_roll_back_and_imported_bank_fields_are_read_only(): void
    {
        $this->actingAs(User::factory()->create());
        $account = Account::factory()->create();
        $category = Category::factory()->create();

        $this->postJson('/api/transactions', [
            'account_id' => $account->id,
            'transaction_date' => '2026-02-01',
            'description' => 'Must roll back',
            'amount' => '-10.0000',
            'splits' => [['category_id' => $category->id, 'amount' => '-9.0000']],
        ])->assertUnprocessable();
        $this->assertDatabaseMissing('transactions', ['description' => 'Must roll back']);
        $this->postJson('/api/transactions', [
            'account_id' => $account->id,
            'transaction_date' => '2026-02-01',
            'description' => 'Too precise',
            'amount' => '-10.00001',
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');

        Tenant::query()->where('slug', 'personal')->firstOrFail()->makeCurrent();
        $imported = Transaction::query()->create([
            'account_id' => $account->id,
            'transaction_date' => '2026-02-01',
            'description' => 'Imported',
            'amount' => '-10.0000',
            'status' => TransactionStatus::Posted,
            'origin' => TransactionOrigin::Csv,
            'posted_at' => now(),
        ]);
        $row = $this->importRow($account);
        $row->update(['transaction_id' => $imported->id, 'status' => ImportRowStatus::Imported]);
        $row->importedMovement->update(['transaction_id' => $imported->id]);
        $this->patchJson("/api/transactions/{$imported->id}", ['amount' => '-11.0000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('transaction');
        $this->patchJson("/api/transactions/{$imported->id}", [
            'category_id' => $category->id,
            'notes' => 'Allowed enrichment',
            'splits' => [['category_id' => $category->id, 'amount' => '-10.0000']],
        ])->assertOk()->assertJsonPath('data.notes', 'Allowed enrichment');
        $this->deleteJson("/api/transactions/{$imported->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('transaction');
    }

    public function test_unique_high_confidence_match_preserves_manual_enrichment(): void
    {
        $account = Account::factory()->create();
        $category = Category::factory()->create();
        $pending = $this->transaction($account, '-42.5000', TransactionStatus::Pending, '2026-03-10', [
            'description' => 'LOCAL MARKET', 'category_id' => $category->id, 'notes' => 'Keep me',
        ]);
        app(TransactionSplitService::class)->replace($pending, [
            ['category_id' => $category->id, 'amount' => '-42.5000'],
        ]);
        $row = $this->importRow($account);

        $matched = app(TransactionMatchingService::class)->process($row, [
            'transaction_date' => '2026-03-11', 'description' => 'LOCAL MARKET', 'amount' => '-42.5000',
        ]);

        $this->assertSame($pending->id, $matched->id);
        $this->assertSame(TransactionStatus::Posted, $matched->status);
        $this->assertSame($category->id, $matched->category_id);
        $this->assertSame('Keep me', $matched->notes);
        $this->assertCount(1, $matched->splits);
        $this->assertSame(ImportRowStatus::Matched, $row->fresh()->status);
    }

    public function test_matched_transactions_keep_enrichment_editable_but_bank_fields_and_delete_locked(): void
    {
        $account = Account::factory()->create();
        $category = Category::factory()->create();
        $pending = $this->transaction($account, '-30.0000', TransactionStatus::Pending, '2026-03-10', [
            'description' => 'MATCHED PAYMENT',
        ]);
        $row = $this->importRow($account);
        $imported = app(TransactionMatchingService::class)->process($row, [
            'transaction_date' => '2026-03-11', 'description' => 'MATCHED PAYMENT', 'amount' => '-30.0000',
        ]);
        $this->actingAs(User::factory()->create());

        $this->patchJson("/api/transactions/{$pending->id}", ['status' => 'pending'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('transaction');
        $this->patchJson("/api/transactions/{$pending->id}", [
            'category_id' => $category->id,
            'notes' => 'Matched enrichment',
            'splits' => [['category_id' => $category->id, 'amount' => '-30.0000']],
        ])->assertOk()->assertJsonPath('data.notes', 'Matched enrichment');
        $this->deleteJson("/api/transactions/{$pending->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('transaction');
    }

    public function test_ambiguous_match_requires_review_and_can_be_confirmed(): void
    {
        $account = Account::factory()->create();
        $category = Category::factory()->create();
        $chosen = $this->transaction($account, '-20.0000', TransactionStatus::Pending, '2026-04-10', [
            'description' => 'COFFEE SHOP', 'category_id' => $category->id, 'notes' => 'chosen',
        ]);
        $other = $this->transaction($account, '-20.0000', TransactionStatus::Pending, '2026-04-11', ['description' => 'COFFEE SHOP']);
        $row = $this->importRow($account);

        $imported = app(TransactionMatchingService::class)->process($row, [
            'transaction_date' => '2026-04-10', 'description' => 'COFFEE SHOP', 'amount' => '-20.0000',
        ]);

        $this->assertSame(ImportRowStatus::NeedsReview, $row->fresh()->status);
        $this->assertSame(TransactionStatus::Posted, $imported->status);
        $this->assertSame(2, MatchSuggestion::query()->count());
        $suggestion = MatchSuggestion::query()->where('pending_transaction_id', $chosen->id)->firstOrFail();
        $confirmed = app(TransactionMatchingService::class)->confirm($suggestion);

        $this->assertSame($chosen->id, $confirmed->id);
        $this->assertSame('chosen', $confirmed->notes);
        $this->assertSoftDeleted('transactions', ['id' => $imported->id]);
        $this->assertSame(TransactionStatus::Pending, $other->fresh()->status);
        $this->assertSame(MatchSuggestionStatus::Confirmed, $suggestion->fresh()->status);
        $this->assertValidationFailure(fn () => app(TransactionMatchingService::class)->confirm($suggestion));
        $this->assertValidationFailure(fn () => app(TransactionMatchingService::class)->reject($suggestion));
        $this->assertSame(MatchSuggestionStatus::Confirmed, $suggestion->fresh()->status);
    }

    public function test_rejecting_all_ambiguous_suggestions_keeps_imported_transaction(): void
    {
        $account = Account::factory()->create();
        $this->transaction($account, '-20.0000', TransactionStatus::Pending, '2026-04-10', ['description' => 'COFFEE SHOP']);
        $this->transaction($account, '-20.0000', TransactionStatus::Pending, '2026-04-11', ['description' => 'COFFEE SHOP']);
        $row = $this->importRow($account);
        $imported = app(TransactionMatchingService::class)->process($row, [
            'transaction_date' => '2026-04-10', 'description' => 'COFFEE SHOP', 'amount' => '-20.0000',
        ]);

        $suggestions = MatchSuggestion::query()->get();
        $suggestions->each(fn (MatchSuggestion $suggestion) => app(TransactionMatchingService::class)->reject($suggestion));

        $this->assertSame(ImportRowStatus::Imported, $row->fresh()->status);
        $this->assertSame(TransactionStatus::Posted, $imported->fresh()->status);
        $this->assertValidationFailure(fn () => app(TransactionMatchingService::class)->reject($suggestions->first()));
    }

    public function test_match_confirmation_rechecks_candidate_and_import_row_under_lock(): void
    {
        $account = Account::factory()->create();
        $candidate = $this->transaction($account, '-20.0000', TransactionStatus::Pending, '2026-04-10', ['description' => 'LOCKED MATCH']);
        $this->transaction($account, '-20.0000', TransactionStatus::Pending, '2026-04-11', ['description' => 'LOCKED MATCH']);
        $row = $this->importRow($account);
        $imported = app(TransactionMatchingService::class)->process($row, [
            'transaction_date' => '2026-04-10', 'description' => 'LOCKED MATCH', 'amount' => '-20.0000',
        ]);
        $suggestion = MatchSuggestion::query()->where('pending_transaction_id', $candidate->id)->firstOrFail();

        $candidate->update(['status' => TransactionStatus::Posted, 'posted_at' => now()]);
        $this->assertValidationFailure(fn () => app(TransactionMatchingService::class)->confirm($suggestion));
        $this->assertSame(ImportRowStatus::NeedsReview, $row->fresh()->status);
        $this->assertSame(MatchSuggestionStatus::Pending, $suggestion->fresh()->status);

        $candidate->update(['status' => TransactionStatus::Pending, 'posted_at' => null]);
        $row->update(['status' => ImportRowStatus::Imported]);
        $this->assertValidationFailure(fn () => app(TransactionMatchingService::class)->confirm($suggestion));
        $this->assertSame(MatchSuggestionStatus::Pending, $suggestion->fresh()->status);

        $row->update(['status' => ImportRowStatus::NeedsReview]);
        DB::table('transactions')->where('id', $imported->id)->update(['deleted_at' => now()]);
        $this->assertValidationFailure(fn () => app(TransactionMatchingService::class)->confirm($suggestion));
        $this->assertSame(MatchSuggestionStatus::Pending, $suggestion->fresh()->status);
    }

    public function test_retroactive_balance_changes_invalidate_but_later_changes_do_not(): void
    {
        $account = Account::factory()->create();
        $january = $this->transaction($account, '100.0000', TransactionStatus::Posted, '2026-01-15');
        $service = app(ReconciliationService::class);
        $reconciliation = $service->reconcile($account, '2026-01-31', '100.0000');
        $this->assertTrue($reconciliation->is_valid);

        $this->transaction($account, '50.0000', TransactionStatus::Posted, '2026-02-01');
        $this->assertTrue($reconciliation->fresh()->is_valid);
        $january->update(['amount' => '90.0000']);
        $this->assertFalse($reconciliation->fresh()->is_valid);
        $this->assertNull($service->latestValid($account));

        $january->update(['amount' => '100.0000']);
        $this->assertSame('2026-01-31', $service->latestValid($account)?->statement_date->toDateString());
    }

    public function test_opening_checkpoint_changes_recalculate_and_can_revalidate_reconciliations(): void
    {
        $account = Account::factory()->create([
            'opening_balance' => '0.0000',
            'opening_balance_date' => '2026-01-01',
        ]);
        $this->transaction($account, '100.0000', TransactionStatus::Posted, '2026-01-15');
        $reconciliation = app(ReconciliationService::class)->reconcile($account, '2026-01-31', '100.0000');
        $this->assertTrue($reconciliation->is_valid);

        $account->update(['opening_balance' => '10.0000']);
        $this->assertFalse($reconciliation->fresh()->is_valid);
        $this->assertSame('110.0000', $reconciliation->fresh()->calculated_balance);

        $account->update(['opening_balance' => '0.0000', 'opening_balance_date' => '2026-01-20']);
        $this->assertFalse($reconciliation->fresh()->is_valid);
        $this->assertSame('0.0000', $reconciliation->fresh()->calculated_balance);

        $account->update(['opening_balance_date' => '2026-01-01']);
        $this->assertTrue($reconciliation->fresh()->is_valid);
    }

    private function transaction(
        Account $account,
        string $amount,
        TransactionStatus $status,
        string $date,
        array $extra = []
    ): Transaction {
        return Transaction::query()->create([
            'account_id' => $account->id,
            'transaction_date' => $date,
            'description' => 'Transaction',
            'amount' => $amount,
            'status' => $status,
            'origin' => TransactionOrigin::Manual,
            'posted_at' => $status === TransactionStatus::Posted ? now() : null,
            ...$extra,
        ]);
    }

    private function importRow(Account $account): ImportRow
    {
        $import = File::query()->create([
            'account_id' => $account->id,
            'filename' => 'test.csv',
            'source_name' => 'RBC',
            'source_type' => 'csv',
            'status' => ImportStatus::Processing,
            'file_fingerprint' => hash('sha256', fake()->uuid()),
        ]);

        $movement = ImportedMovement::query()->create([
            'account_id' => $account->id,
            'source_name' => 'rbc',
            'fingerprint' => hash('sha256', fake()->uuid()),
            'occurrence' => 1,
        ]);

        return ImportRow::query()->create([
            'import_id' => $import->id,
            'account_id' => $account->id,
            'imported_movement_id' => $movement->id,
            'line_number' => 1,
            'raw_payload' => [],
            'normalized_payload' => [],
            'fingerprint' => hash('sha256', fake()->uuid()),
            'occurrence' => 1,
            'status' => ImportRowStatus::Pending,
        ]);
    }

    private function createTenantGraph(string $slug): void
    {
        $account = Account::factory()->create(['name' => "Graph {$slug}"]);
        $category = Category::factory()->create(['name' => "Graph {$slug}"]);
        $pending = $this->transaction($account, '-5.0000', TransactionStatus::Pending, '2026-05-01', [
            'description' => 'Graph transaction',
        ]);
        TransactionSplit::query()->create([
            'transaction_id' => $pending->id,
            'category_id' => $category->id,
            'amount' => '-5.0000',
        ]);
        $import = File::query()->create([
            'account_id' => $account->id,
            'filename' => "graph-{$slug}.csv",
            'source_name' => 'RBC',
            'source_type' => 'csv',
            'status' => ImportStatus::Processing,
            'file_fingerprint' => hash('sha256', "graph-{$slug}"),
        ]);
        $row = ImportRow::query()->create([
            'import_id' => $import->id,
            'account_id' => $account->id,
            'line_number' => 99,
            'raw_payload' => [],
            'normalized_payload' => [],
            'fingerprint' => hash('sha256', 'same-bank-row'),
            'occurrence' => 1,
            'status' => ImportRowStatus::NeedsReview,
        ]);
        $movement = ImportedMovement::query()->create([
            'account_id' => $account->id,
            'source_name' => 'rbc',
            'fingerprint' => hash('sha256', "graph-movement-{$slug}"),
            'occurrence' => 1,
        ]);
        $row->update(['imported_movement_id' => $movement->id]);
        MatchSuggestion::query()->create([
            'import_row_id' => $row->id,
            'pending_transaction_id' => $pending->id,
            'status' => MatchSuggestionStatus::Pending,
            'confidence' => '1.0000',
        ]);
        app(ReconciliationService::class)->reconcile($account, '2026-05-31', '0.0000');
    }

    private function assertValidationFailure(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected a validation failure.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }
}
