<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends BaseModel
{
    use HasFactory;
    protected $fillable = [
        "account_number",
        "transaction_date",
        "description",
        "amount",
        "category_id",
    ];
    public $timestamps = ["transaction_date"];
    protected array $allowedFilters = [
        "account_number",
        "transaction_date",
        "description",
        "amount",
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(
            Account::class,
            "account_number",
            "account_number"
        );
    }

    public function scopeBelongsToCategoryGroup($query, $categoryId): void
    {
        // special case for "uncategorized"
        if ((int) $categoryId === -1) {
            $query->whereNull("category_id");
        }

        $category = Category::query()->find($categoryId);
        if (!$category) {
            return;
        }

        // belong to category or any of the category children or any category children's children
        $childrenIds = $category->children->pluck("id")->push($categoryId);
        $childrenIds = $childrenIds->merge(
            $category
                ->children()
                ->with("children")
                ->get()
                ->pluck("children")
                ->flatten()
                ->pluck("id")
        );
        $childrenIds = $childrenIds->unique();
        $childrenIds = $childrenIds->values();
        $childrenIds = $childrenIds->toArray();

        $query->where(function ($query) use ($categoryId, $childrenIds) {
            $query->orWhere("category_id", $categoryId);
            $query->orWhereIn("category_id", $childrenIds);
        });
    }
}
