<?php

namespace App\Services\FileReader;

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

    private static function isValidCsvContent(string $filePath): bool
    {
        return is_file($filePath) && is_readable($filePath) && filesize($filePath) > 0;
    }

    private static function isRbcFileFormat(string $filePath): bool
    {
        $handler = @fopen($filePath, 'r');
        if ($handler === false) {
            return false;
        }
        $header = fgetcsv($handler);
        fclose($handler);

        return $header === ['Account Type', 'Account Number', 'Transaction Date', 'Cheque Number', 'Description 1', 'Description 2', 'CAD$', 'USD$'];
    }

    private static function isTriangleFileFormat(string $filePath): bool
    {
        $handler = @fopen($filePath, 'r');
        if ($handler === false) {
            return false;
        }
        $header = fgetcsv($handler);
        fclose($handler);

        return $header === ['MY ACCOUNT TRANSACTIONS'];
    }
}
