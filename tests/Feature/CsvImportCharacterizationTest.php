<?php

namespace Tests\Feature;

use App\Enums\ImportRowStatus;
use App\Enums\TransactionOrigin;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\File;
use App\Models\ImportedMovement;
use App\Models\ImportRow;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CsvImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CsvImportCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rbc_rows_are_imported_and_duplicate_rows_are_ignored(): void
    {
        $this->importFixture('tests/fixtures/csv76698.csv', 'RBC Test');

        $this->assertDatabaseCount(Transaction::class, 10);
        $this->assertDatabaseHas(Transaction::class, [
            'transaction_date' => '2022-12-19',
            'description' => 'UTILITY BILL PMT Enbridge Gas',
            'amount' => -158.1700,
            'status' => TransactionStatus::Posted->value,
            'origin' => TransactionOrigin::Csv->value,
        ]);

        $this->processExistingImport();
        $this->assertDatabaseCount(Transaction::class, 10);
    }

    public function test_triangle_rows_are_normalized_and_duplicate_rows_are_ignored(): void
    {
        $this->importFixture('tests/fixtures/Transactions.csv', 'Triangle Test');

        $this->assertDatabaseCount(Transaction::class, 10);
        $this->assertDatabaseHas(Transaction::class, [
            'transaction_date' => '2024-07-05',
            'description' => 'Amazon.ca*R760B1P02',
            'amount' => -24.1500,
        ]);

        $this->processExistingImport();
        $this->assertDatabaseCount(Transaction::class, 10);
    }

    public function test_the_same_bank_rows_in_a_different_file_do_not_duplicate_transactions(): void
    {
        $source = base_path('tests/fixtures/csv76698.csv');
        $account = Account::factory()->create(['name' => 'RBC duplicate test']);
        $service = app(CsvImportService::class);
        $first = $service->create($account, 'tests/fixtures/csv76698.csv', 'first.csv', $source);
        $service->process($first, $source);

        $secondPath = $this->temporaryCsv(file_get_contents($source).PHP_EOL);
        try {
            $second = $service->create($account, $secondPath, 'second.csv', $secondPath);
            $service->process($second, $secondPath);
        } finally {
            @unlink($secondPath);
        }

        $this->assertDatabaseCount(Transaction::class, 10);
        $this->assertSame(10, ImportRow::query()->where('status', ImportRowStatus::Duplicate->value)->count());
    }

    public function test_overlapping_rbc_exports_with_different_comma_quoting_are_idempotent(): void
    {
        $header = 'Account Type,Account Number,Transaction Date,Cheque Number,Description 1,Description 2,CAD$,USD$';
        $firstPath = $this->temporaryCsv($header."\nChequing,1234,08/01/2026,CHK-1,BASE,one,two,-12.34,\n");
        $secondPath = $this->temporaryCsv($header."\nChequing,1234,08/01/2026,CHK-1,BASE,\"one,two\",-12.34,\n");
        $account = Account::factory()->create(['account_number' => '1234', 'currency' => 'CAD']);
        $service = app(CsvImportService::class);

        try {
            $first = $service->create($account, $firstPath, 'first.csv', $firstPath);
            $service->process($first, $firstPath);
            $second = $service->create($account, $secondPath, 'second.csv', $secondPath);
            $service->process($second, $secondPath);
        } finally {
            @unlink($firstPath);
            @unlink($secondPath);
        }

        $this->assertDatabaseCount(Transaction::class, 1);
        $this->assertSame('BASE one,two', Transaction::query()->firstOrFail()->description);
        $this->assertSame(ImportRowStatus::Duplicate, $second->rows()->firstOrFail()->status);
    }

    public function test_currency_mismatch_fails_before_creating_a_transaction_or_movement_identity(): void
    {
        $header = 'Account Type,Account Number,Transaction Date,Cheque Number,Description 1,Description 2,CAD$,USD$';
        $path = $this->temporaryCsv($header."\nChequing,1234,08/01/2026,CHK-1,USD ROW,,,12.34\n");
        $account = Account::factory()->create(['account_number' => '1234', 'currency' => 'CAD']);
        $service = app(CsvImportService::class);

        try {
            $import = $service->create($account, $path, 'usd.csv', $path);
            $service->process($import, $path);
        } finally {
            @unlink($path);
        }

        $row = $import->rows()->firstOrFail();
        $this->assertSame(ImportRowStatus::Failed, $row->status);
        $this->assertSame('USD', $row->normalized_payload['currency']);
        $this->assertSame('CSV row currency does not match the selected account currency.', $row->error_message);
        $this->assertStringNotContainsString('1234', $row->error_message);
        $this->assertStringNotContainsString('12.34', $row->error_message);
        $this->assertDatabaseCount(Transaction::class, 0);
        $this->assertDatabaseCount(ImportedMovement::class, 0);
    }

    public function test_identical_legitimate_rows_use_occurrences_and_a_concurrency_safe_identity(): void
    {
        $header = 'Account Type,Account Number,Transaction Date,Cheque Number,Description 1,Description 2,CAD$,USD$';
        $line = 'Chequing,1234,08/01/2026,,SAME PAYMENT,,-12.34,';
        $firstPath = $this->temporaryCsv($header."\n".$line."\n".$line."\n");
        $secondPath = $this->temporaryCsv($header."\r\n".$line."\r\n".$line."\r\n");
        $account = Account::factory()->create(['name' => 'Identical occurrence test']);
        $service = app(CsvImportService::class);

        try {
            $first = $service->create($account, $firstPath, 'first.csv', $firstPath);
            $service->process($first, $firstPath);
            $this->assertSame($first->id, $service->create($account, $firstPath, 'again.csv', $firstPath)->id);
            $service->process($first, $firstPath);

            $second = $service->create($account, $secondPath, 'second.csv', $secondPath);
            $service->process($second, $secondPath);
        } finally {
            @unlink($firstPath);
            @unlink($secondPath);
        }

        $this->assertSame(2, Transaction::query()->count());
        $this->assertSame([1, 2], ImportedMovement::query()->orderBy('occurrence')->pluck('occurrence')->all());
        $this->assertSame(2, $second->rows()->where('status', ImportRowStatus::Duplicate->value)->count());

        $identity = ImportedMovement::query()->orderBy('occurrence')->firstOrFail();
        $inserted = DB::table('imported_movements')->insertOrIgnore([
            'tenant_id' => $identity->tenant_id,
            'account_id' => $identity->account_id,
            'transaction_id' => $identity->transaction_id,
            'source_name' => $identity->source_name,
            'fingerprint' => $identity->fingerprint,
            'occurrence' => $identity->occurrence,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertSame(0, $inserted);
        $this->assertSame(2, ImportedMovement::query()->count());
    }

    public function test_file_identity_is_scoped_by_account_and_unused_duplicate_upload_is_deleted(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $firstAccount = Account::factory()->create();
        $secondAccount = Account::factory()->create();
        $contents = "Account Type,Account Number,Transaction Date,Cheque Number,Description 1,Description 2,CAD$,USD$\n"
            ."Chequing,1234,08/01/2026,CHK-1,STORAGE TEST,,-12.34,\n";

        $this->post('/api/files', [
            'account_id' => $firstAccount->id,
            'file' => UploadedFile::fake()->createWithContent('first.csv', $contents),
        ])->assertSuccessful();
        $this->post('/api/files', [
            'account_id' => $firstAccount->id,
            'file' => UploadedFile::fake()->createWithContent('duplicate.csv', $contents),
        ])->assertSuccessful();

        $this->assertCount(1, Storage::disk('local')->allFiles('files'));
        Tenant::query()->where('slug', 'personal')->firstOrFail()->makeCurrent();
        $this->assertSame(1, File::query()->where('account_id', $firstAccount->id)->count());

        $service = app(CsvImportService::class);
        $path = $this->temporaryCsv($contents);
        try {
            $otherAccountImport = $service->create($secondAccount, $path, 'same.csv', $path);
        } finally {
            @unlink($path);
        }
        $this->assertSame($secondAccount->id, $otherAccountImport->account_id);
        $this->assertSame(2, File::query()->count());
    }

    private function importFixture(string $relativePath, string $accountName): void
    {
        $path = base_path($relativePath);
        $account = Account::factory()->create(['name' => $accountName]);
        $service = app(CsvImportService::class);
        $import = $service->create($account, $relativePath, basename($path), $path);
        $service->process($import, $path);
    }

    private function processExistingImport(): void
    {
        $import = File::query()->firstOrFail();
        app(CsvImportService::class)->process($import, base_path($import->filename));
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
