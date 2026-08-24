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
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\TransactionSplitService;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

class Phase5CMatchReviewApiTest extends ApiTestCase
{
    public function test_index_requires_authentication_and_a_valid_tenant_and_has_an_empty_state(): void
    {
        $this->getJson('/api/match-suggestions')->assertUnauthorized();

        $this->actingAsAdmin();
        $this->withHeader('X-Tenant-Slug', '')->getJson('/api/match-suggestions')->assertNotFound();
        $this->withHeader('X-Tenant-Slug', 'personal')->getJson('/api/match-suggestions')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.per_page', 15);
    }

    public function test_index_paginates_import_rows_filters_accounts_and_has_stable_ordering(): void
    {
        $this->actingAsAdmin();
        $account = Account::factory()->create();
        $otherAccount = Account::factory()->create();
        $old = $this->createReview($account, '2026-01-01', 2);
        $sameDateFirst = $this->createReview($account, '2026-01-03', 2);
        $sameDateSecond = $this->createReview($account, '2026-01-03', 3);
        $other = $this->createReview($otherAccount, '2026-01-04', 2);

        $response = $this->getJson("/api/match-suggestions?account_id={$account->id}&per_page=2&page=1")
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('data.0.id', $sameDateSecond->id)
            ->assertJsonPath('data.1.id', $sameDateFirst->id)
            ->assertJsonCount(3, 'data.0.candidates');

        $this->assertStringContainsString("account_id={$account->id}", $response->json('links.next'));
        $this->assertStringContainsString('per_page=2', $response->json('links.next'));
        $this->getJson("/api/match-suggestions?account_id={$account->id}&per_page=2&page=2")
            ->assertOk()
            ->assertJsonPath('data.0.id', $old->id);
        $this->getJson('/api/match-suggestions?per_page=51')
            ->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson('/api/match-suggestions?page=0')
            ->assertUnprocessable()->assertJsonValidationErrors('page');
        $this->getJson('/api/match-suggestions?per_page=50')
            ->assertOk()->assertJsonPath('meta.per_page', 50)->assertJsonPath('data.0.id', $other->id);
    }

    public function test_index_returns_the_explicit_decimal_safe_grouped_contract(): void
    {
        $this->actingAsAdmin();
        $account = Account::factory()->create([
            'account_number' => 'PRIVATE-987',
            'name' => 'Clinic Chequing',
            'type' => 'chequing',
        ]);
        $parent = Category::factory()->create(['name' => 'Expenses']);
        $category = Category::factory()->create([
            'parent_id' => $parent->id,
            'level' => 2,
            'name' => 'Medical supplies',
        ]);
        $row = $this->createReview($account, '2026-02-10', 3, [
            ['date' => '2026-02-13', 'confidence' => '0.9000'],
            ['date' => '2026-02-09', 'confidence' => '0.9000'],
            ['date' => '2026-02-10', 'confidence' => '1.0000'],
        ], [
            'original_filename' => null,
            'source_name' => 'RBC',
            'amount' => '-42.5000',
            'category_id' => $category->id,
            'notes' => 'Bank note',
        ]);
        $candidate = $row->suggestions()->orderByDesc('confidence')->firstOrFail()->pendingTransaction;
        $candidate->update(['category_id' => $category->id, 'notes' => 'Manual note']);
        app(TransactionSplitService::class)->replace($candidate, [[
            'category_id' => $category->id,
            'amount' => '-42.5000',
            'description' => 'Frames',
        ]]);
        app(TransactionSplitService::class)->replace($row->transaction, [[
            'category_id' => $category->id,
            'amount' => '-42.5000',
            'description' => 'Imported split',
        ]]);

        $response = $this->getJson('/api/match-suggestions')->assertOk()->assertJsonCount(1, 'data');
        $review = $response->json('data.0');

        $this->assertSame(
            ['id', 'account', 'import', 'line_number', 'imported_transaction', 'candidates'],
            array_keys($review)
        );
        $this->assertSame(['id', 'name', 'type', 'currency'], array_keys($review['account']));
        $this->assertSame(['id', 'original_filename', 'source_name', 'created_at'], array_keys($review['import']));
        $this->assertNull($review['import']['original_filename']);
        $this->assertSame('-42.5000', $review['imported_transaction']['amount']);
        $this->assertSame('1.0000', $review['candidates'][0]['confidence']);
        $this->assertSame('2026-02-09', $review['candidates'][1]['transaction']['transaction_date']);
        $this->assertSame('2026-02-13', $review['candidates'][2]['transaction']['transaction_date']);
        $this->assertSame('Manual note', $review['candidates'][0]['transaction']['notes']);
        $this->assertSame('-42.5000', $review['candidates'][0]['transaction']['splits'][0]['amount']);
        $this->assertSame($parent->id, $review['candidates'][0]['transaction']['category']['parent']['id']);
        $this->assertSame(
            ['id', 'transaction_date', 'amount', 'description', 'notes', 'status', 'origin', 'category', 'splits'],
            array_keys($review['imported_transaction'])
        );
        $this->assertSame(['suggestion_id', 'confidence', 'transaction'], array_keys($review['candidates'][0]));
        foreach (['tenant_id', 'account_number', 'raw_payload', 'normalized_payload', 'fingerprint',
            'occurrence', 'imported_movement_id', 'file_path', 'token', 'bank_reference'] as $field) {
            $this->assertStringNotContainsString($field, $response->getContent());
        }
        $this->assertStringNotContainsString('internal/', $response->getContent());
    }

    public function test_index_only_returns_actionable_reviews_and_eager_loads_with_bounded_queries(): void
    {
        $this->actingAsAdmin();
        $account = Account::factory()->create();
        foreach (range(1, 5) as $day) {
            $this->createReview($account, "2026-03-0{$day}", 3);
        }
        $notReview = $this->createReview($account, '2026-03-06', 2);
        $notReview->update(['status' => ImportRowStatus::Imported]);
        $noPending = $this->createReview($account, '2026-03-07', 2);
        $noPending->suggestions()->update(['status' => MatchSuggestionStatus::Rejected]);
        $missingImported = $this->createReview($account, '2026-03-08', 2);
        DB::table('transactions')->where('id', $missingImported->transaction_id)->update(['deleted_at' => now()]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/match-suggestions?per_page=15')
            ->assertOk()->assertJsonPath('meta.total', 5)->assertJsonCount(5, 'data');
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(22, $queryCount, "Expected a bounded query count, got {$queryCount}.");
    }

    public function test_tenant_isolation_filters_and_route_binding_fail_closed(): void
    {
        $this->actingAsAdmin();
        $personal = Tenant::query()->where('slug', 'personal')->firstOrFail();
        $clinic = Tenant::query()->where('slug', 'clinic')->firstOrFail();
        $personalAccount = Account::factory()->create(['name' => 'Personal account']);
        $personalRow = $this->createReview($personalAccount, '2026-04-01', 2);
        [$clinicAccount, $clinicRow] = $clinic->execute(function (): array {
            $account = Account::factory()->create(['name' => 'Clinic account']);

            return [$account, $this->createReview($account, '2026-04-02', 2)];
        });
        $personal->makeCurrent();
        $clinicSuggestion = $clinic->execute(fn () => $clinicRow->suggestions()->firstOrFail());
        $personalSuggestion = $personalRow->suggestions()->firstOrFail();
        $personal->makeCurrent();

        $this->withHeader('X-Tenant-Slug', 'personal')->getJson('/api/match-suggestions')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.account.name', 'Personal account')
            ->assertJsonMissing(['name' => 'Clinic account']);
        $this->withHeader('X-Tenant-Slug', 'clinic')->getJson('/api/match-suggestions')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.account.name', 'Clinic account')
            ->assertJsonMissing(['name' => 'Personal account']);
        $this->withHeader('X-Tenant-Slug', 'personal')
            ->getJson("/api/match-suggestions?account_id={$clinicAccount->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('account_id');
        $this->withHeader('X-Tenant-Slug', 'personal')
            ->postJson("/api/match-suggestions/{$clinicSuggestion->id}/confirm")->assertNotFound();
        $this->withHeader('X-Tenant-Slug', 'personal')
            ->postJson("/api/match-suggestions/{$clinicSuggestion->id}/reject")->assertNotFound();
        $this->withHeader('X-Tenant-Slug', 'clinic')
            ->postJson("/api/match-suggestions/{$personalSuggestion->id}/confirm")->assertNotFound();
    }

    public function test_financial_queries_fail_closed_without_a_current_tenant(): void
    {
        $account = Account::factory()->create();
        $this->createReview($account, '2026-04-03', 2);

        Tenant::forgetCurrent();

        $this->assertSame(0, ImportRow::query()->count());
        $this->assertSame(0, MatchSuggestion::query()->count());
        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_confirm_returns_full_transaction_resolves_the_case_and_rejects_siblings(): void
    {
        $this->actingAsAdmin();
        $account = Account::factory()->create();
        $category = Category::factory()->create();
        $row = $this->createReview($account, '2026-05-10', 2, [], ['amount' => '-75.2500']);
        $importedId = $row->transaction_id;
        $chosenSuggestion = $row->suggestions()->orderBy('id')->firstOrFail();
        $sibling = $row->suggestions()->whereKeyNot($chosenSuggestion->id)->firstOrFail();
        $chosen = $chosenSuggestion->pendingTransaction;
        $chosen->update([
            'transaction_date' => '2026-05-09',
            'description' => 'Manual enriched description',
            'notes' => 'Keep this note',
            'category_id' => $category->id,
        ]);
        app(TransactionSplitService::class)->replace($chosen, [[
            'category_id' => $category->id, 'amount' => '-75.2500', 'description' => 'Keep split',
        ]]);

        $response = $this->postJson("/api/match-suggestions/{$chosenSuggestion->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.review_id', $row->id)
            ->assertJsonPath('data.suggestion_id', $chosenSuggestion->id)
            ->assertJsonPath('data.resolution', 'matched')
            ->assertJsonPath('data.transaction.id', $chosen->id)
            ->assertJsonPath('data.transaction.transaction_date', '2026-05-10')
            ->assertJsonPath('data.transaction.amount', '-75.2500')
            ->assertJsonPath('data.transaction.description', 'Manual enriched description')
            ->assertJsonPath('data.transaction.notes', 'Keep this note')
            ->assertJsonPath('data.transaction.status', 'posted')
            ->assertJsonPath('data.transaction.origin', 'manual')
            ->assertJsonPath('data.transaction.is_import_linked', true)
            ->assertJsonPath('data.transaction.bank_fields_editable', false)
            ->assertJsonPath('data.transaction.deletable', false)
            ->assertJsonPath('data.transaction.splits.0.description', 'Keep split');
        Tenant::query()->where('slug', 'personal')->firstOrFail()->makeCurrent();

        $this->assertSame(
            ['id', 'account_id', 'account', 'transaction_date', 'amount', 'description', 'notes', 'status',
                'origin', 'posted_at', 'category_id', 'category', 'splits', 'is_import_linked',
                'bank_fields_editable', 'deletable'],
            array_keys($response->json('data.transaction'))
        );
        $this->assertSame(ImportRowStatus::Matched, $row->fresh()->status);
        $this->assertSame(MatchSuggestionStatus::Confirmed, $chosenSuggestion->fresh()->status);
        $this->assertSame(MatchSuggestionStatus::Rejected, $sibling->fresh()->status);
        $this->assertSame($chosen->id, $row->importedMovement->fresh()->transaction_id);
        $this->assertSoftDeleted('transactions', ['id' => $importedId]);
        $this->getJson('/api/match-suggestions')->assertOk()->assertJsonPath('data', []);
        $this->postJson("/api/match-suggestions/{$chosenSuggestion->id}/confirm")
            ->assertUnprocessable()->assertJsonValidationErrors('suggestion');
        Tenant::query()->where('slug', 'personal')->firstOrFail()->makeCurrent();
        $this->assertSame(1, MatchSuggestion::query()->where('status', MatchSuggestionStatus::Confirmed)->count());
    }

    public function test_reject_handles_partial_and_final_resolution_without_deleting_the_import(): void
    {
        $this->actingAsAdmin();
        $account = Account::factory()->create();
        $row = $this->createReview($account, '2026-06-01', 2);
        $importedId = $row->transaction_id;
        $suggestions = $row->suggestions()->orderBy('id')->get();

        $this->postJson("/api/match-suggestions/{$suggestions[0]->id}/reject")
            ->assertOk()
            ->assertJsonPath('data.review_id', $row->id)
            ->assertJsonPath('data.resolution', 'candidate_rejected')
            ->assertJsonPath('data.remaining_candidates', 1);
        $this->getJson('/api/match-suggestions')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonCount(1, 'data.0.candidates')
            ->assertJsonPath('data.0.candidates.0.suggestion_id', $suggestions[1]->id);
        $this->postJson("/api/match-suggestions/{$suggestions[0]->id}/reject")
            ->assertUnprocessable()->assertJsonValidationErrors('suggestion');

        $this->postJson("/api/match-suggestions/{$suggestions[1]->id}/reject")
            ->assertOk()
            ->assertJsonPath('data.resolution', 'imported_transaction_kept')
            ->assertJsonPath('data.remaining_candidates', 0);
        Tenant::query()->where('slug', 'personal')->firstOrFail()->makeCurrent();
        $this->assertSame(ImportRowStatus::Imported, $row->fresh()->status);
        $this->assertSame(TransactionStatus::Posted, Transaction::query()->findOrFail($importedId)->status);
        $this->getJson('/api/match-suggestions')->assertOk()->assertJsonPath('data', []);
        $this->postJson("/api/match-suggestions/{$suggestions[1]->id}/reject")
            ->assertUnprocessable()->assertJsonValidationErrors('suggestion');
    }

    public function test_actions_return_422_for_every_stale_review_condition(): void
    {
        $this->actingAsAdmin();
        $account = Account::factory()->create();

        $candidateChanged = $this->createReview($account, '2026-07-01', 2);
        $candidateSuggestion = $candidateChanged->suggestions()->firstOrFail();
        $candidateSuggestion->pendingTransaction->update([
            'status' => TransactionStatus::Posted, 'posted_at' => now(),
        ]);
        $this->postJson("/api/match-suggestions/{$candidateSuggestion->id}/confirm")
            ->assertUnprocessable()->assertJsonValidationErrors('suggestion');
        $this->postJson("/api/match-suggestions/{$candidateSuggestion->id}/reject")
            ->assertUnprocessable()->assertJsonValidationErrors('suggestion');

        $rowChanged = $this->createReview($account, '2026-07-02', 2);
        $rowSuggestion = $rowChanged->suggestions()->firstOrFail();
        $rowChanged->update(['status' => ImportRowStatus::Imported]);
        $this->postJson("/api/match-suggestions/{$rowSuggestion->id}/reject")
            ->assertUnprocessable()->assertJsonValidationErrors('suggestion');

        $transactionGone = $this->createReview($account, '2026-07-03', 2);
        $goneSuggestion = $transactionGone->suggestions()->firstOrFail();
        DB::table('transactions')->where('id', $transactionGone->transaction_id)->update(['deleted_at' => now()]);
        $this->postJson("/api/match-suggestions/{$goneSuggestion->id}/reject")
            ->assertUnprocessable()->assertJsonValidationErrors('suggestion');

        $movementChanged = $this->createReview($account, '2026-07-04', 2);
        $movementSuggestion = $movementChanged->suggestions()->firstOrFail();
        $unrelated = Transaction::factory()->create([
            'account_id' => $account->id,
            'status' => TransactionStatus::Posted,
            'origin' => TransactionOrigin::Csv,
        ]);
        $movementChanged->importedMovement->update(['transaction_id' => $unrelated->id]);
        $this->postJson("/api/match-suggestions/{$movementSuggestion->id}/confirm")
            ->assertUnprocessable()->assertJsonValidationErrors('suggestion');
        $this->postJson("/api/match-suggestions/{$movementSuggestion->id}/reject")
            ->assertUnprocessable()->assertJsonValidationErrors('suggestion');
    }

    /**
     * @param  array<int, array{date?: string, confidence?: string}>  $candidateData
     * @param  array<string, mixed>  $importData
     */
    private function createReview(
        Account $account,
        string $date,
        int $candidateCount,
        array $candidateData = [],
        array $importData = []
    ): ImportRow {
        if (Tenant::current() === null) {
            Tenant::query()->where('slug', 'personal')->firstOrFail()->makeCurrent();
        }

        $amount = $importData['amount'] ?? '-42.5000';
        $import = File::query()->create([
            'account_id' => $account->id,
            'filename' => 'internal/'.fake()->uuid().'.csv',
            'original_filename' => array_key_exists('original_filename', $importData)
                ? $importData['original_filename']
                : 'statement.csv',
            'source_name' => $importData['source_name'] ?? 'RBC',
            'source_type' => 'csv',
            'status' => ImportStatus::Complete,
            'file_fingerprint' => hash('sha256', fake()->uuid()),
        ]);
        $imported = Transaction::factory()->create([
            'account_id' => $account->id,
            'transaction_date' => $date,
            'description' => 'Imported bank movement',
            'notes' => $importData['notes'] ?? null,
            'amount' => $amount,
            'category_id' => $importData['category_id'] ?? null,
            'status' => TransactionStatus::Posted,
            'origin' => TransactionOrigin::Csv,
            'posted_at' => now(),
        ]);
        $movement = ImportedMovement::query()->create([
            'account_id' => $account->id,
            'transaction_id' => $imported->id,
            'source_name' => 'rbc',
            'fingerprint' => hash('sha256', fake()->uuid()),
            'occurrence' => 1,
        ]);
        $row = ImportRow::query()->create([
            'import_id' => $import->id,
            'account_id' => $account->id,
            'transaction_id' => $imported->id,
            'imported_movement_id' => $movement->id,
            'line_number' => fake()->numberBetween(2, 1000),
            'raw_payload' => ['bank_reference' => 'PRIVATE'],
            'normalized_payload' => ['amount' => $amount],
            'fingerprint' => hash('sha256', fake()->uuid()),
            'occurrence' => 1,
            'status' => ImportRowStatus::NeedsReview,
        ]);

        foreach (range(0, $candidateCount - 1) as $index) {
            $candidate = Transaction::factory()->create([
                'account_id' => $account->id,
                'transaction_date' => $candidateData[$index]['date'] ?? $date,
                'description' => "Manual candidate {$index}",
                'amount' => $amount,
                'status' => TransactionStatus::Pending,
                'origin' => TransactionOrigin::Manual,
            ]);
            MatchSuggestion::query()->create([
                'import_row_id' => $row->id,
                'pending_transaction_id' => $candidate->id,
                'status' => MatchSuggestionStatus::Pending,
                'confidence' => $candidateData[$index]['confidence'] ?? '0.9000',
            ]);
        }

        return $row;
    }
}
