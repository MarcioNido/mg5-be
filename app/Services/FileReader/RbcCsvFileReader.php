<?php

namespace App\Services\FileReader;

use App\Services\Money;
use Generator;
use Illuminate\Support\Carbon;
use RuntimeException;
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
            $raw = $line;
            try {
                if (count($line) < 8) {
                    throw new RuntimeException('RBC row must contain at least eight columns.');
                }

                if ($this->hasLegacyTrailingColumn($line)) {
                    array_pop($line);
                }

                $description2 = implode(',', array_slice($line, self::DESCRIPTION_1 + 1, -2));
                $cadAmount = $line[count($line) - 2];
                $usdAmount = $line[count($line) - 1];
                $hasCadAmount = $cadAmount !== '';
                $hasUsdAmount = $usdAmount !== '';

                if ($hasCadAmount === $hasUsdAmount) {
                    throw new RuntimeException('RBC row must contain exactly one currency amount.');
                }

                $amount = $hasCadAmount ? $cadAmount : $usdAmount;
                Money::units($amount);

                yield [
                    'line_number' => $lineNumber,
                    'raw' => $raw,
                    'normalized' => [
                        'account_number' => $line[self::ACCOUNT_NUMBER],
                        'bank_reference' => trim($line[self::CHEQUE_NUMBER]) ?: null,
                        'transaction_date' => Carbon::createFromFormat('m/d/Y', $line[self::TRANSACTION_DATE])->toDateString(),
                        'description' => trim($line[self::DESCRIPTION_1].' '.$description2),
                        'amount' => $amount,
                        'currency' => $hasCadAmount ? 'CAD' : 'USD',
                    ],
                ];
            } catch (Throwable $exception) {
                yield ['line_number' => $lineNumber, 'raw' => $raw, 'normalized' => null, 'error' => $exception->getMessage()];
            }
        }
    }

    private function hasLegacyTrailingColumn(array $line): bool
    {
        if (count($line) < 9 || $line[count($line) - 1] !== '') {
            return false;
        }

        $standardHasOneAmount = $this->hasExactlyOneAmount(
            $line[count($line) - 2],
            $line[count($line) - 1],
        );
        $legacyHasOneAmount = $this->hasExactlyOneAmount(
            $line[count($line) - 3],
            $line[count($line) - 2],
        );

        if (! $standardHasOneAmount && $legacyHasOneAmount) {
            return true;
        }

        // A legacy USD row is structurally ambiguous with a current CAD row.
        // An empty legacy CAD column identifies the former without relying on
        // descriptions or account names.
        return $standardHasOneAmount
            && $legacyHasOneAmount
            && $line[count($line) - 3] === '';
    }

    private function hasExactlyOneAmount(string $cadAmount, string $usdAmount): bool
    {
        return ($cadAmount !== '') !== ($usdAmount !== '');
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
