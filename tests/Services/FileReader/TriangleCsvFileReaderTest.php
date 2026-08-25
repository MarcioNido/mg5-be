<?php

namespace Tests\Services\FileReader;

use App\Services\FileReader\TriangleCsvFileReader;
use App\Services\FileReader\UnsupportedFileTypeException;
use Tests\TestCase;

class TriangleCsvFileReaderTest extends TestCase
{
    /**
     * @throws UnsupportedFileTypeException
     */
    public function test_process_file(): void
    {
        $reader = new TriangleCsvFileReader(base_path('tests/fixtures/Transactions.csv'));
        $rows = iterator_to_array($reader->rows());
        $this->assertCount(10, $rows);
        $this->assertSame('7727', $rows[0]['normalized']['account_number']);
        $this->assertSame('-24.1500', $rows[0]['normalized']['amount']);
        $this->assertSame('CAD', $rows[0]['normalized']['currency']);
        $this->assertNotEmpty($rows[0]['normalized']['bank_reference']);
    }
}
