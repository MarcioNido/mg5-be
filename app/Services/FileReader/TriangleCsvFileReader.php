<?php

namespace App\Services\FileReader;

use App\Services\Money;
use Generator;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * REF,TRANSACTION DATE,POSTED DATE,TYPE,DESCRIPTION,Category,AMOUNT
 * Reads RBC CSV exported files
 * Array
 * (
 *   [0] => Ref
 *   [1] => Transaction Date
 *   [2] => Posted Date
 *   [3] => Type
 *   [4] => Description
 *   [5] => Category
 *   [6] => Amount
 * )
 */
class TriangleCsvFileReader implements CsvFileReader
{
    protected $handler;

    const ACCOUNT_NUMBER = '7727';

    const REFERENCE = 0;

    const TRANSACTION_DATE = 1;

    const DESCRIPTION = 4;

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
        return 'Triangle';
    }

    public function rows(): Generator
    {
        $this->rewind();
        for ($lineNumber = 1; $lineNumber <= 4; $lineNumber++) {
            $this->line();
        }
        while ($line = $this->line()) {
            $lineNumber++;
            try {
                yield [
                    'line_number' => $lineNumber,
                    'raw' => $line,
                    'normalized' => [
                        'account_number' => self::ACCOUNT_NUMBER,
                        'bank_reference' => trim($line[self::REFERENCE]) ?: null,
                        'transaction_date' => Carbon::createFromFormat('Y-m-d', $line[self::TRANSACTION_DATE])->toDateString(),
                        'description' => trim($line[self::DESCRIPTION]),
                        'amount' => Money::decimal(-Money::units($line[self::AMOUNT])),
                        'currency' => 'CAD',
                    ],
                ];
            } catch (Throwable $exception) {
                yield ['line_number' => $lineNumber, 'raw' => $line, 'normalized' => null, 'error' => $exception->getMessage()];
            }
        }
    }

    public function line(): bool|array
    {
        return fgetcsv($this->handler, null, ',', '"', '');
    }

    public function rewind(): void
    {
        rewind($this->handler);
    }
}
