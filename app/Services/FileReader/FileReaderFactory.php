<?php

namespace App\Services\FileReader;

class FileReaderFactory
{
    private const RBC_HEADER = ['Account Type', 'Account Number', 'Transaction Date', 'Cheque Number', 'Description 1', 'Description 2', 'CAD$', 'USD$'];

    private const TRIANGLE_HEADER = ['MY ACCOUNT TRANSACTIONS'];

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
        return self::header($filePath) === self::RBC_HEADER;
    }

    private static function isTriangleFileFormat(string $filePath): bool
    {
        return self::header($filePath) === self::TRIANGLE_HEADER;
    }

    private static function header(string $filePath): array|false
    {
        $handler = @fopen($filePath, 'r');
        if ($handler === false) {
            return false;
        }

        $header = fgetcsv($handler, null, ',', '"', '');
        fclose($handler);

        if ($header !== false && isset($header[0]) && str_starts_with($header[0], "\xEF\xBB\xBF")) {
            $header[0] = substr($header[0], 3);
        }

        return $header;
    }
}
