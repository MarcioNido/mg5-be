<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends BaseModel
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = ['parent_id', 'name', 'level', 'type'];

    public static function booted(): void
    {
        static::saving(function (Category $category): void {
            if ($category->exists && ! $category->isDirty('parent_id')) {
                return;
            }

            $category->level = $category->parent()->first()?->level + 1 ?? 1;
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function splits(): HasMany
    {
        return $this->hasMany(TransactionSplit::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(Rule::class);
    }
}
