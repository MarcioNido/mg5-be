<?php

namespace App\Enums;

enum TransactionOrigin: string
{
    case Manual = 'manual';
    case Csv = 'csv';
    case System = 'system';
}
