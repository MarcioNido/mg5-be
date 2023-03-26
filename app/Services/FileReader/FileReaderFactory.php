<?php

namespace App\Services\FileReader;

use Illuminate\Support\Str;

class FileReaderFactory
{
    /**
     * @throws UnsupportedFileTypeException
     */
    public static function make(string $filePath)
    {
        if (self::isValidCsvContent($filePath)) {
            return new RbcCsvFileReader($filePath);
        }

        throw new UnsupportedFileTypeException();
    }

    private static function isValidCsvContent(string $fileContent): bool
    {
        return Str::endsWith($fileContent, '.csv') || Str::endsWith($fileContent, '.txt');
    }
}
