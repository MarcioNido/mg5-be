<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionSplit extends Model
{
    use BelongsToTenant;

    protected $fillable = ['transaction_id', 'category_id', 'amount', 'description'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
