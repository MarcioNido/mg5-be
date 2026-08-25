<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class LiteralContains
{
    public static function apply(Builder $query, string $column, string $text): Builder
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower($text));

        return $query->whereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", ["%{$escaped}%"]);
    }
}
