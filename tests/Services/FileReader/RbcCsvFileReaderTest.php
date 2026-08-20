<?php

namespace Tests\Services\FileReader;

use App\Models\Transaction;
use App\Services\FileReader\RbcCsvFileReader;
use App\Services\FileReader\UnsupportedFileTypeException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbcCsvFileReaderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @throws UnsupportedFileTypeException
     */
    public function test_process_file(): void
    {
        $reader = new RbcCsvFileReader(base_path('tests/fixtures/csv76698.csv'));
        $reader->processFile();
        $this->assertDatabaseCount(Transaction::class, 10);
    }
}
