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
}
