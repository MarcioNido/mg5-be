<?php

namespace App\Services\FileReader;

use Generator;

interface CsvFileReader
{
    public function sourceName(): string;

    /** @return Generator<int, array{line_number:int, raw:array, normalized:array}> */
    public function rows(): Generator;
}
