<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reconciliation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'account_id', 'statement_date', 'entered_bank_balance',
        'calculated_balance', 'reconciled_at',
    ];

    protected function casts(): array
    {
        return [
            'statement_date' => 'date',
            'entered_bank_balance' => 'decimal:4',
            'calculated_balance' => 'decimal:4',
            'reconciled_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function getIsValidAttribute(): bool
    {
        return Money::units($this->entered_bank_balance) === Money::units($this->calculated_balance);
    }
}
