<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends BaseModel
{
    use HasFactory;

    protected $fillable = ["parent_id", "name", "level", "type"];

    public static function booted(): void
    {
        static::saving(function (Category $category) {
            $category->level = $category->parent?->level + 1 ?? 1;
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, "parent_id");
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, "parent_id");
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
