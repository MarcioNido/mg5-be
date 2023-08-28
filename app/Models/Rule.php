<?php

namespace App\Models;

use App\Traits\HasPageSizeConfiguration;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Rule extends BaseModel
{
    use HasFactory, HasPageSizeConfiguration, SoftDeletes;

    protected $fillable = ["content", "account_number", "category_id"];

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
