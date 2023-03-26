<?php

namespace Tests\Services\FileReader;

use App\Models\Transaction;
use App\Services\FileReader\RbcCsvFileReader;
use App\Services\FileReader\UnsupportedFileTypeException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class RbcCsvFileReaderTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @throws UnsupportedFileTypeException
     */
    public function testProcessFile()
    {
        $reader = new RbcCsvFileReader(base_path('tests/fixtures/csv76698.csv'));
        $reader->processFile();
        $this->assertDatabaseCount(Transaction::class, 10);
    }
}
