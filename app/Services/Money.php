<?php

namespace App\Services;

use InvalidArgumentException;

final class Money
{
    public static function units(int|float|string $amount): int
    {
        $value = trim((string) $amount);

        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,4}))?$/', $value, $matches)) {
            throw new InvalidArgumentException("Invalid monetary amount: {$value}");
        }

        $fraction = str_pad($matches[3] ?? '', 4, '0');
        $units = ((int) $matches[2] * 10000) + (int) $fraction;

        return ($matches[1] ?? '') === '-' ? -$units : $units;
    }

    public static function decimal(int $units): string
    {
        $sign = $units < 0 ? '-' : '';
        $units = abs($units);

        return sprintf('%s%d.%04d', $sign, intdiv($units, 10000), $units % 10000);
    }

    public static function formatUnits(int $units, int $scale = 2): string
    {
        if ($scale < 0 || $scale > 4) {
            throw new InvalidArgumentException('Money display scale must be between 0 and 4.');
        }

        $factor = 10 ** (4 - $scale);
        $rounded = intdiv(abs($units) + intdiv($factor, 2), $factor);
        $sign = $units < 0 ? '-' : '';

        if ($scale === 0) {
            return $sign.$rounded;
        }

        $scaleFactor = 10 ** $scale;

        return sprintf('%s%d.%0'.$scale.'d', $sign, intdiv($rounded, $scaleFactor), $rounded % $scaleFactor);
    }
}
