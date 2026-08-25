<?php

namespace Tests\Services\FileReader;

use App\Services\FileReader\FileReaderFactory;
use App\Services\FileReader\RbcCsvFileReader;
use App\Services\FileReader\UnsupportedFileTypeException;
use Tests\TestCase;

class RbcCsvFileReaderTest extends TestCase
{
    private const HEADER = 'Account Type,Account Number,Transaction Date,Cheque Number,Description 1,Description 2,CAD$,USD$';

    public function test_factory_accepts_exact_rbc_headers_with_or_without_one_bom(): void
    {
        foreach ([self::HEADER, "\xEF\xBB\xBF".self::HEADER] as $header) {
            $path = $this->temporaryCsv($header."\n".$this->row('DETAIL', '-12.34', ''));

            try {
                $this->assertInstanceOf(RbcCsvFileReader::class, FileReaderFactory::make($path));
            } finally {
                @unlink($path);
            }
        }
    }

    public function test_factory_rejects_non_exact_empty_truncated_malformed_and_unknown_headers(): void
    {
        foreach ([
            '',
            'Account Type,Account Number',
            'unknown,columns',
            ' Account Type,Account Number,Transaction Date,Cheque Number,Description 1,Description 2,CAD$,USD$',
            "\xEF\xBB\xBF\xEF\xBB\xBF".self::HEADER,
        ] as $contents) {
            $path = $this->temporaryCsv($contents);

            try {
                try {
                    FileReaderFactory::make($path);
                    $this->fail('An unsupported header was accepted.');
                } catch (UnsupportedFileTypeException) {
                    $this->assertTrue(true);
                }
            } finally {
                @unlink($path);
            }
        }
    }

    public function test_reconstructs_exact_quoted_and_unquoted_description_commas(): void
    {
        $path = $this->temporaryCsv(self::HEADER."\n".
            $this->row('DETAIL', '-12.34', '').
            "Chequing,1234,08/01/2026,CHK-2,BASE,one,two,-23.45,\n".
            "Chequing,1234,08/02/2026,CHK-3,BASE,one,two,three,-34.56,\n".
            "Chequing,1234,08/03/2026,CHK-4,BASE,\"quoted, detail\",-45.67,\n"
        );

        try {
            $rows = iterator_to_array((new RbcCsvFileReader($path))->rows());
        } finally {
            @unlink($path);
        }

        $this->assertCount(4, $rows);
        $this->assertSame('BASE DETAIL', $rows[0]['normalized']['description']);
        $this->assertSame('BASE one,two', $rows[1]['normalized']['description']);
        $this->assertSame('BASE one,two,three', $rows[2]['normalized']['description']);
        $this->assertSame('BASE quoted, detail', $rows[3]['normalized']['description']);
        $this->assertSame(['Chequing', '1234', '08/01/2026', 'CHK-1', 'BASE', 'DETAIL', '-12.34', ''], $rows[0]['raw']);
    }

    public function test_selects_one_final_currency_and_rejects_invalid_rows(): void
    {
        $path = $this->temporaryCsv(self::HEADER."\n".
            $this->row('CAD', '-12.34', '').
            $this->row('USD', '', '23.45').
            $this->row('BOTH', '1.00', '2.00').
            $this->row('NEITHER', '', '').
            "Chequing,1234,08/01/2026,CHK-1,TOO,SHORT,-1.00\n".
            $this->row('BAD MONEY', '1.23456', '')
        );

        try {
            $rows = iterator_to_array((new RbcCsvFileReader($path))->rows());
        } finally {
            @unlink($path);
        }

        $this->assertSame(['amount' => '-12.34', 'currency' => 'CAD'], array_intersect_key($rows[0]['normalized'], array_flip(['amount', 'currency'])));
        $this->assertSame(['amount' => '23.45', 'currency' => 'USD'], array_intersect_key($rows[1]['normalized'], array_flip(['amount', 'currency'])));
        $this->assertSame('RBC row must contain exactly one currency amount.', $rows[2]['error']);
        $this->assertSame('RBC row must contain exactly one currency amount.', $rows[3]['error']);
        $this->assertSame('RBC row must contain at least eight columns.', $rows[4]['error']);
        $this->assertStringContainsString('Invalid monetary amount', $rows[5]['error']);
        $this->assertNull($rows[5]['normalized']);
    }

    public function test_application_csv_reads_do_not_emit_php_84_deprecations(): void
    {
        $path = $this->temporaryCsv("\xEF\xBB\xBF".self::HEADER."\n".$this->row('DETAIL', '-12.34', ''));
        $deprecations = [];
        set_error_handler(function (int $severity, string $message) use (&$deprecations): bool {
            if ($severity === E_DEPRECATED) {
                $deprecations[] = $message;
            }

            return false;
        });

        try {
            iterator_to_array(FileReaderFactory::make($path)->rows());
        } finally {
            restore_error_handler();
            @unlink($path);
        }

        $this->assertSame([], $deprecations);
    }

    private function row(string $description2, string $cad, string $usd): string
    {
        return "Chequing,1234,08/01/2026,CHK-1,BASE,{$description2},{$cad},{$usd}\n";
    }

    private function temporaryCsv(string $contents): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'mg5-rbc-');
        $path = $temporary.'.csv';
        rename($temporary, $path);
        file_put_contents($path, $contents);

        return $path;
    }
}
