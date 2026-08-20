<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportedMovement extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'account_id', 'transaction_id', 'source_name', 'fingerprint', 'occurrence',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function importRows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }
}
