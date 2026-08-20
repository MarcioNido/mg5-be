<?php

namespace App\Services\FileReader;

use Illuminate\Support\Str;

class FileReaderFactory
{
    /**
     * @throws UnsupportedFileTypeException
     */
    public static function make(string $filePath): CsvFileReader
    {
        if (! self::isValidCsvContent($filePath)) {
            throw new UnsupportedFileTypeException;
        }

        if (self::isRbcFileFormat($filePath)) {
            return new RbcCsvFileReader($filePath);
        }

        if (self::isTriangleFileFormat($filePath)) {
            return new TriangleCsvFileReader($filePath);
        }

        throw new UnsupportedFileTypeException;
    }

    private static function isValidCsvContent(string $fileContent): bool
    {
        return Str::endsWith($fileContent, '.csv') || Str::endsWith($fileContent, '.txt');
    }

    private static function isRbcFileFormat(string $filePath): bool
    {
        $handler = fopen($filePath, 'r');
        $header = fgetcsv($handler);
        fclose($handler);

        return $header === ['Account Type', 'Account Number', 'Transaction Date', 'Cheque Number', 'Description 1', 'Description 2', 'CAD$', 'USD$'];
    }

    private static function isTriangleFileFormat(string $filePath): bool
    {
        $handler = fopen($filePath, 'r');
        $header = fgetcsv($handler);
        fclose($handler);

        return $header === ['MY ACCOUNT TRANSACTIONS'];
    }
}
