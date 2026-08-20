<?php

namespace App\Enums;

enum ImportRowStatus: string
{
    case Pending = 'pending';
    case Imported = 'imported';
    case Matched = 'matched';
    case NeedsReview = 'needs_review';
    case Duplicate = 'duplicate';
    case Failed = 'failed';
}
