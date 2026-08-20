<?php

namespace App\Enums;

enum ImportStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Complete = 'complete';
    case CompleteWithErrors = 'complete_with_errors';
    case Failed = 'failed';
}
