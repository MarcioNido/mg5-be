<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\ReconciliationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string|null $account_number
 */
class Account extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'account_number', 'name', 'type', 'currency',
        'opening_balance', 'opening_balance_date',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:4',
            'opening_balance_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updated(function (Account $account): void {
            if ($account->wasChanged(['opening_balance', 'opening_balance_date'])) {
                app(ReconciliationService::class)->recalculate($account);
            }
        });
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function reconciliations(): HasMany
    {
        return $this->hasMany(Reconciliation::class);
    }
}
