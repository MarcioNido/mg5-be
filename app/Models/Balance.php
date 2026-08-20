<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Balance extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'account_number',
        'last_day_of_month',
        'final_balance',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_number', 'account_number');
    }
}
