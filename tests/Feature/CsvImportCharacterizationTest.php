<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Services\FileReader\RbcCsvFileReader;
use App\Services\FileReader\TriangleCsvFileReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CsvImportCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rbc_rows_are_imported_and_duplicate_rows_are_ignored(): void
    {
        $reader = new RbcCsvFileReader(base_path('tests/fixtures/csv76698.csv'));
        $reader->processFile();

        $this->assertDatabaseCount(Transaction::class, 10);
        $this->assertDatabaseHas(Transaction::class, [
            'account_number' => '06402-5031752',
            'transaction_date' => '2022-12-19',
            'description' => 'UTILITY BILL PMT Enbridge Gas',
            'amount' => -158.17,
        ]);

        (new RbcCsvFileReader(base_path('tests/fixtures/csv76698.csv')))->processFile();

        $this->assertDatabaseCount(Transaction::class, 10);
    }

    public function test_triangle_rows_are_normalized_and_duplicate_rows_are_ignored(): void
    {
        $reader = new TriangleCsvFileReader(base_path('tests/fixtures/Transactions.csv'));
        $reader->processFile();

        $this->assertDatabaseCount(Transaction::class, 10);
        $this->assertDatabaseHas(Transaction::class, [
            'account_number' => '7727',
            'transaction_date' => '2024-07-05',
            'description' => 'Amazon.ca*R760B1P02',
            'amount' => -24.15,
        ]);

        (new TriangleCsvFileReader(base_path('tests/fixtures/Transactions.csv')))->processFile();

        $this->assertDatabaseCount(Transaction::class, 10);
    }
}
