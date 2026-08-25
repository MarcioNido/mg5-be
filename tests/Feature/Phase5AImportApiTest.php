<?php

namespace Tests\Feature;

use App\Enums\ImportRowStatus;
use App\Enums\ImportStatus;
use App\Models\Account;
use App\Models\File;
use App\Models\ImportRow;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CsvImportService;
use App\Services\FileReader\UnsupportedFileTypeException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Phase5AImportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounts_are_listed_and_created_with_the_stable_tenant_aware_contract(): void
    {
        $user = $this->userWithBothTenants();
        $personal = $this->tenant('personal');
        $clinic = $this->tenant('clinic');
        $personal->execute(fn () => Account::factory()->create([
            'account_number' => 'SHARED-100',
            'name' => 'Personal Existing',
        ]));
        $clinic->execute(fn () => Account::factory()->create([
            'account_number' => 'SHARED-100',
            'name' => 'Clinic Existing',
        ]));

        $this->actingAs($user)
            ->withHeader('X-Tenant-Slug', 'personal')
            ->getJson('/api/accounts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Personal Existing')
            ->assertJsonMissing(['name' => 'Clinic Existing'])
            ->assertJsonMissingPath('data.0.transactions');

        $this->actingAs($user)
            ->withHeader('X-Tenant-Slug', 'personal')
            ->postJson('/api/accounts', [
                'account_number' => null,
                'name' => 'Personal Cash',
                'type' => 'cash',
                'opening_balance' => '12.3456',
            ])
            ->assertCreated()
            ->assertJsonPath('data.currency', 'CAD')
            ->assertJsonPath('data.opening_balance', '12.3456')
            ->assertJsonPath('data.opening_balance_date', null);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Slug', 'clinic')
            ->postJson('/api/accounts', [
                'account_number' => 'CLINIC-NEW',
                'name' => 'Clinic Chequing',
                'type' => 'chequing',
                'opening_balance_date' => '2026-08-20',
            ])
            ->assertCreated()
            ->assertJsonPath('data.currency', 'CAD')
            ->assertJsonPath('data.opening_balance', '0.0000')
            ->assertJsonPath('data.opening_balance_date', '2026-08-20');

        $this->actingAs($user)
            ->withHeader('X-Tenant-Slug', 'personal')
            ->postJson('/api/accounts', [
                'account_number' => 'SHARED-100',
                'name' => 'Duplicate Personal',
                'type' => 'other',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('account_number');
    }

    public function test_rbc_and_triangle_uploads_store_original_names_and_expose_duplicate_metadata(): void
    {
        Storage::fake('local');
        $user = $this->userWithBothTenants();
        $account = Account::factory()->create();

        $rbc = file_get_contents(base_path('tests/fixtures/csv76698.csv'));
        $newUpload = $this->actingAs($user)->post('/api/files', [
            'account_id' => $account->id,
            'file' => UploadedFile::fake()->createWithContent('rbc-original.csv', $rbc),
        ]);

        $newUpload->assertCreated()
            ->assertJsonPath('meta.duplicate_upload', false)
            ->assertJsonPath('data.original_filename', 'rbc-original.csv')
            ->assertJsonPath('data.source_name', 'RBC')
            ->assertJsonPath('data.status', 'complete')
            ->assertJsonMissingPath('data.filename')
            ->assertJsonMissingPath('data.file_fingerprint')
            ->assertJsonMissingPath('data.tenant_id');

        $this->actingAs($user)->post('/api/files', [
            'account_id' => $account->id,
            'file' => UploadedFile::fake()->createWithContent('renamed.csv', $rbc),
        ])->assertOk()
            ->assertJsonPath('meta.duplicate_upload', true)
            ->assertJsonPath('data.id', $newUpload->json('data.id'))
            ->assertJsonPath('data.original_filename', 'rbc-original.csv')
            ->assertJsonPath('data.status', 'complete');

        $this->tenant('personal')->makeCurrent();
        $triangleAccount = Account::factory()->create();
        $triangle = file_get_contents(base_path('tests/fixtures/Transactions.csv'));
        $this->actingAs($user)->post('/api/files', [
            'account_id' => $triangleAccount->id,
            'file' => UploadedFile::fake()->createWithContent('triangle.txt', $triangle),
        ])->assertCreated()
            ->assertJsonPath('meta.duplicate_upload', false)
            ->assertJsonPath('data.source_name', 'Triangle')
            ->assertJsonPath('data.original_filename', 'triangle.txt')
            ->assertJsonPath('data.status', 'complete');

        $clinicAccount = $this->tenant('clinic')->execute(fn () => Account::factory()->create());
        $clinicUpload = $this->actingAs($user)
            ->withHeader('X-Tenant-Slug', 'clinic')
            ->post('/api/files', [
                'account_id' => $clinicAccount->id,
                'file' => UploadedFile::fake()->createWithContent('same-rbc.csv', $rbc),
            ]);
        $clinicUpload->assertCreated()
            ->assertJsonPath('meta.duplicate_upload', false);
        $this->assertNotSame($newUpload->json('data.id'), $clinicUpload->json('data.id'));

        $this->assertCount(3, Storage::disk('local')->allFiles('files'));
        $this->assertDatabaseHas('imports', ['original_filename' => 'rbc-original.csv']);
    }

    public function test_unknown_or_empty_csv_returns_a_file_validation_error_without_leaving_storage(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $account = Account::factory()->create();

        foreach (['unknown.csv' => "unknown,columns\nvalue,one\n", 'empty.csv' => ''] as $name => $contents) {
            $this->actingAs($user)->withHeader('Accept', 'application/json')->post('/api/files', [
                'account_id' => $account->id,
                'file' => UploadedFile::fake()->createWithContent($name, $contents),
            ])->assertUnprocessable()
                ->assertJsonValidationErrors('file')
                ->assertJsonFragment([
                    'Unsupported CSV format. Upload an RBC or Triangle statement export.',
                ]);
        }

        $this->assertSame([], Storage::disk('local')->allFiles('files'));
        $this->assertDatabaseCount('imports', 0);
    }

    public function test_duplicate_upload_retries_rows_that_previously_failed_to_parse(): void
    {
        Storage::fake('local');
        $user = $this->userWithBothTenants();
        $account = Account::factory()->create(['account_number' => null, 'currency' => 'CAD']);
        $contents = "Account Type,Account Number,Transaction Date,Cheque Number,Description 1,Description 2,CAD$,USD$\n".
            "Chequing,,08/19/2026,,RETRY ROW,,-10.00,,\n";
        $storedPath = 'files/previously-failed.csv';
        Storage::disk('local')->put($storedPath, $contents);
        $import = File::factory()->create([
            'account_id' => $account->id,
            'filename' => $storedPath,
            'original_filename' => 'previously-failed.csv',
            'status' => ImportStatus::CompleteWithErrors,
            'file_fingerprint' => hash('sha256', $contents),
            'total_rows' => 1,
            'processed_rows' => 0,
            'failed_rows' => 1,
        ]);
        $this->row($import, 2, null, ImportRowStatus::Failed);

        $this->actingAs($user)->post('/api/files', [
            'account_id' => $account->id,
            'file' => UploadedFile::fake()->createWithContent('retry.csv', $contents),
        ])->assertOk()
            ->assertJsonPath('meta.duplicate_upload', true)
            ->assertJsonPath('data.id', $import->id)
            ->assertJsonPath('data.status', 'complete')
            ->assertJsonPath('data.processed_rows', 1)
            ->assertJsonPath('data.failed_rows', 0);

        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseHas('import_rows', [
            'import_id' => $import->id,
            'line_number' => 2,
            'status' => ImportRowStatus::Imported->value,
            'error_message' => null,
        ]);
    }

    public function test_upload_extension_and_size_failures_use_laravel_file_errors(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $account = Account::factory()->create();

        $this->actingAs($user)->withHeader('Accept', 'application/json')->post('/api/files', [
            'account_id' => $account->id,
            'file' => UploadedFile::fake()->createWithContent('statement.pdf', 'not a CSV'),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('file')
            ->assertJsonFragment(['The file must have a .csv or .txt extension.']);

        $this->actingAs($user)->withHeader('Accept', 'application/json')->post('/api/files', [
            'account_id' => $account->id,
            'file' => UploadedFile::fake()->create('too-large.csv', 10241, 'text/csv'),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('file')
            ->assertJsonFragment(['The file must not be larger than 10 MB.']);

        $this->assertSame([], Storage::disk('local')->allFiles('files'));
    }

    public function test_import_history_is_paginated_ordered_filtered_and_eager_loads_accounts(): void
    {
        $user = User::factory()->create();
        $firstAccount = Account::factory()->create(['name' => 'First account']);
        $secondAccount = Account::factory()->create(['name' => 'Second account']);
        Carbon::setTestNow('2026-08-20 12:00:00');

        foreach (range(1, 17) as $number) {
            Carbon::setTestNow(Carbon::now()->addMinute());
            File::factory()->create([
                'account_id' => $number % 2 === 0 ? $firstAccount->id : $secondAccount->id,
                'status' => $number % 3 === 0 ? ImportStatus::Failed : ImportStatus::Complete,
                'original_filename' => "statement-{$number}.csv",
            ]);
        }
        Carbon::setTestNow();

        $response = $this->actingAs($user)->getJson('/api/files?per_page=5');
        $response->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 17)
            ->assertJsonPath('data.0.original_filename', 'statement-17.csv')
            ->assertJsonPath('data.0.account.name', 'Second account');

        $this->actingAs($user)->getJson("/api/files?account_id={$firstAccount->id}&status=failed&per_page=50")
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.account_id', $firstAccount->id)
            ->assertJsonPath('data.0.status', 'failed');

        $this->actingAs($user)->getJson('/api/files?per_page=51&status=unknown')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page', 'status']);
    }

    public function test_import_detail_uses_safe_rows_in_line_order(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['name' => 'Safe details']);
        $import = File::factory()->create([
            'account_id' => $account->id,
            'original_filename' => null,
            'status' => ImportStatus::CompleteWithErrors,
        ]);
        $this->row($import, 4, [
            'account_number' => 'SECRET-ACCOUNT',
            'bank_reference' => 'SECRET-REFERENCE',
            'transaction_date' => '2026-08-19',
            'description' => 'Visible description',
            'amount' => '-12.3',
        ]);
        $this->row($import, 2, null, ImportRowStatus::Failed);

        $this->actingAs($user)->getJson("/api/files/{$import->id}")
            ->assertOk()
            ->assertJsonPath('data.original_filename', null)
            ->assertJsonPath('data.account.name', 'Safe details')
            ->assertJsonPath('data.rows.0.line_number', 2)
            ->assertJsonPath('data.rows.1.line_number', 4)
            ->assertJsonPath('data.rows.1.transaction_date', '2026-08-19')
            ->assertJsonPath('data.rows.1.description', 'Visible description')
            ->assertJsonPath('data.rows.1.amount', '-12.3000')
            ->assertJsonMissingPath('data.rows.1.tenant_id')
            ->assertJsonMissingPath('data.rows.1.fingerprint')
            ->assertJsonMissingPath('data.rows.1.occurrence')
            ->assertJsonMissingPath('data.rows.1.imported_movement_id')
            ->assertJsonMissingPath('data.rows.1.raw_payload')
            ->assertJsonMissingPath('data.rows.1.normalized_payload')
            ->assertJsonMissing(['account_number' => 'SECRET-ACCOUNT'])
            ->assertJsonMissing(['bank_reference' => 'SECRET-REFERENCE']);
    }

    public function test_imports_are_isolated_between_personal_and_clinic(): void
    {
        $user = $this->userWithBothTenants();
        $personal = $this->tenant('personal');
        $clinic = $this->tenant('clinic');
        $personalImport = $personal->execute(fn () => File::factory()->create(['original_filename' => 'personal.csv']));
        $clinicImport = $clinic->execute(fn () => File::factory()->create(['original_filename' => 'clinic.csv']));

        $this->actingAs($user)->withHeader('X-Tenant-Slug', 'clinic')
            ->getJson('/api/files')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $clinicImport->id)
            ->assertJsonMissing(['original_filename' => 'personal.csv']);

        $this->actingAs($user)->withHeader('X-Tenant-Slug', 'clinic')
            ->getJson("/api/files/{$personalImport->id}")
            ->assertNotFound();

        $this->actingAs($user)->withHeader('X-Tenant-Slug', 'clinic')
            ->getJson("/api/files?account_id={$personalImport->account_id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('account_id');
    }

    public function test_row_failures_complete_with_errors_and_global_failures_are_recorded(): void
    {
        $account = Account::factory()->create();
        $service = app(CsvImportService::class);
        $partialPath = $this->temporaryCsv(
            "Account Type,Account Number,Transaction Date,Cheque Number,Description 1,Description 2,CAD$,USD$\n".
            "Chequing,,08/19/2026,,VALID ROW,,-10.00,\n".
            "Chequing,,not-a-date,,INVALID ROW,,-5.00,\n"
        );
        $globalPath = $this->temporaryCsv(file_get_contents(base_path('tests/fixtures/csv76698.csv')));

        try {
            $partial = $service->create($account, $partialPath, 'partial.csv', $partialPath);
            $service->process($partial, $partialPath);
            $this->assertSame(ImportStatus::CompleteWithErrors, $partial->fresh()->status);
            $this->assertSame(2, $partial->fresh()->total_rows);
            $this->assertSame(1, $partial->fresh()->processed_rows);
            $this->assertSame(1, $partial->fresh()->failed_rows);

            $global = $service->create($account, $globalPath, 'global.csv', $globalPath);
            file_put_contents($globalPath, "unsupported\n");

            try {
                $service->process($global, $globalPath);
                $this->fail('A global reader failure was expected.');
            } catch (UnsupportedFileTypeException) {
                $this->assertSame(ImportStatus::Failed, $global->fresh()->status);
                $this->assertNotNull($global->fresh()->error_message);
                $this->assertSame(0, $global->fresh()->processed_rows);
                $this->assertSame(0, $global->fresh()->failed_rows);
            }
        } finally {
            @unlink($partialPath);
            @unlink($globalPath);
        }
    }

    private function row(
        File $import,
        int $line,
        ?array $normalized,
        ImportRowStatus $status = ImportRowStatus::Imported
    ): ImportRow {
        return ImportRow::query()->create([
            'import_id' => $import->id,
            'account_id' => $import->account_id,
            'line_number' => $line,
            'raw_payload' => ['secret' => 'raw'],
            'normalized_payload' => $normalized,
            'fingerprint' => hash('sha256', "{$import->id}-{$line}"),
            'occurrence' => 9,
            'status' => $status,
            'error_message' => $status === ImportRowStatus::Failed ? 'Invalid row.' : null,
        ]);
    }

    private function userWithBothTenants(): User
    {
        $user = User::factory()->create();
        $user->tenants()->sync(Tenant::query()->pluck('id'));

        return $user;
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::query()->where('slug', $slug)->firstOrFail();
    }

    private function temporaryCsv(string $contents): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'mg5-');
        $path = $temporary.'.csv';
        rename($temporary, $path);
        file_put_contents($path, $contents);

        return $path;
    }
}
