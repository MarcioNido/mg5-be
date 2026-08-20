<?php

namespace Tests\Services\FileReader;

use App\Services\FileReader\RbcCsvFileReader;
use App\Services\FileReader\UnsupportedFileTypeException;
use Tests\TestCase;

class RbcCsvFileReaderTest extends TestCase
{
    /**
     * @throws UnsupportedFileTypeException
     */
    public function test_process_file(): void
    {
        $reader = new RbcCsvFileReader(base_path('tests/fixtures/csv76698.csv'));
        $rows = iterator_to_array($reader->rows());
        $this->assertCount(10, $rows);
        $this->assertSame('06402-5031752', $rows[0]['normalized']['account_number']);
        $this->assertArrayHasKey('bank_reference', $rows[0]['normalized']);
    }
}
