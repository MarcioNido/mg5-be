<?php

namespace App\Services\FileReader;

use App\Models\Transaction;
use Illuminate\Support\Carbon;

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
class TriangleCsvFileReader
{
    protected $handler;

    const ACCOUNT_NUMBER = '7727';
    const TRANSACTION_DATE = 1;
    const DESCRIPTION = 4;
    const AMOUNT = 6;

    /**
     * @throws UnsupportedFileTypeException
     */
    public function __construct(protected string $filePath)
    {
        $this->handler = fopen($this->filePath, 'r');
        if (!$this->handler) {
            throw new UnsupportedFileTypeException();
        }
    }

    public function processFile(): void
    {
        $this->line(); // skip header
        $this->line(); // skip header
        $this->line(); // skip header
        $this->line(); // skip header

        while ($line = $this->line()) {
            Transaction::query()->firstOrCreate([
                'account_number' => self::ACCOUNT_NUMBER,
                'transaction_date' => Carbon::createFromFormat('Y-m-d', $line[self::TRANSACTION_DATE])->toDateString(),
                'description' => trim($line[self::DESCRIPTION]),
                'amount' => ($line[self::AMOUNT] * -1),
            ]);
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
