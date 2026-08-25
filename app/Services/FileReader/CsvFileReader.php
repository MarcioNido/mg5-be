<?php

namespace App\Services\FileReader;

use Generator;

interface CsvFileReader
{
    public function sourceName(): string;

    /** @return Generator<int, array{line_number:int, raw:array, normalized:array{account_number:string, bank_reference:?string, transaction_date:string, description:string, amount:string, currency:string}}> */
    public function rows(): Generator;
}
