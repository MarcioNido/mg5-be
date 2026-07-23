<?php

namespace Tests\Services\FileReader;

use App\Models\Transaction;
use App\Services\FileReader\TriangleCsvFileReader;
use App\Services\FileReader\UnsupportedFileTypeException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TriangleCsvFileReaderTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @throws UnsupportedFileTypeException
     */
    public function testProcessFile()
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
    }
}
