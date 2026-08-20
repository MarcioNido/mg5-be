<?php

namespace App\Enums;

enum MatchSuggestionStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
}
