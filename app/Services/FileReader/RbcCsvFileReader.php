<?php

namespace App\Services\FileReader;

use Generator;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Reads RBC CSV exported files
 * Array
 * (
 *   [0] => Account Type
 *   [1] => Account Number
 *   [2] => Transaction Date
 *   [3] => Cheque Number
 *   [4] => Description 1
 *   [5] => Description 2
 *   [6] => CAD$
 *   [7] => USD$
 * )
 */
class RbcCsvFileReader implements CsvFileReader
{
    protected $handler;

    const ACCOUNT_NUMBER = 1;

    const TRANSACTION_DATE = 2;

    const CHEQUE_NUMBER = 3;

    const DESCRIPTION_1 = 4;

    const DESCRIPTION_2 = 5;

    const AMOUNT = 6;

    /**
     * @throws UnsupportedFileTypeException
     */
    public function __construct(protected string $filePath)
    {
        $this->handler = fopen($this->filePath, 'r');
        if (! $this->handler) {
            throw new UnsupportedFileTypeException;
        }
    }

    public function sourceName(): string
    {
        return 'RBC';
    }

    public function rows(): Generator
    {
        $this->rewind();
        $this->line();
        $lineNumber = 1;
        while ($line = $this->line()) {
            $lineNumber++;
            try {
                yield [
                    'line_number' => $lineNumber,
                    'raw' => $line,
                    'normalized' => [
                        'account_number' => $line[self::ACCOUNT_NUMBER],
                        'bank_reference' => trim($line[self::CHEQUE_NUMBER]) ?: null,
                        'transaction_date' => Carbon::createFromFormat('m/d/Y', $line[self::TRANSACTION_DATE])->toDateString(),
                        'description' => trim($line[self::DESCRIPTION_1].' '.$line[self::DESCRIPTION_2]),
                        'amount' => $line[self::AMOUNT],
                    ],
                ];
            } catch (Throwable $exception) {
                yield ['line_number' => $lineNumber, 'raw' => $line, 'normalized' => null, 'error' => $exception->getMessage()];
            }
        }
    }

    public function line(): bool|array
    {
        return fgetcsv($this->handler);
    }

    public function rewind(): void
    {
        rewind($this->handler);
    }
}
