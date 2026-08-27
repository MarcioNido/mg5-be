<?php

namespace App\Models;

use App\Enums\TransactionOrigin;
use App\Enums\TransactionStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Services\ReconciliationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class Transaction extends BaseModel
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'account_id',
        'transaction_date',
        'description',
        'notes',
        'amount',
        'category_id',
        'status',
        'origin',
        'posted_at',
        'ignored_at',
    ];

    protected array $allowedFilters = [
        'account_id',
        'transaction_date',
        'status',
        'origin',
        'category_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'posted_at' => 'datetime',
            'ignored_at' => 'datetime',
            'status' => TransactionStatus::class,
            'origin' => TransactionOrigin::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Transaction $transaction): void {
            if ($transaction->isDirty(['account_id', 'transaction_date', 'amount', 'status'])
                && $transaction->isLinkedToImport()) {
                throw ValidationException::withMessages([
                    'transaction' => 'Bank fields of a transaction linked to an import are read-only.',
                ]);
            }
        });

        static::deleting(function (Transaction $transaction): void {
            if ($transaction->isLinkedToImport()) {
                throw ValidationException::withMessages([
                    'transaction' => 'A transaction linked to an import cannot be deleted directly.',
                ]);
            }
        });

        static::saved(function (Transaction $transaction): void {
            if ($transaction->wasRecentlyCreated || $transaction->wasChanged(['account_id', 'transaction_date', 'amount', 'status', 'ignored_at'])) {
                app(ReconciliationService::class)->recalculateForMutation($transaction, $transaction->getOriginal());
            }
        });

        static::deleted(fn (Transaction $transaction) => app(ReconciliationService::class)
            ->recalculateForMutation($transaction, $transaction->getOriginal()));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function splits(): HasMany
    {
        return $this->hasMany(TransactionSplit::class);
    }

    public function importRows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    public function importedMovements(): HasMany
    {
        return $this->hasMany(ImportedMovement::class);
    }

    public function isLinkedToImport(): bool
    {
        return $this->importRows()->exists() || $this->importedMovements()->exists();
    }

    public function scopeFinanciallyActive(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('ignored_at'));
    }

    public function scopeBelongsToCategoryGroup($query, $categoryId): void
    {
        // special case for "uncategorized"
        if ((int) $categoryId === -1) {
            $query->whereNull('category_id');
        }

        $category = Category::query()->find($categoryId);
        if (! $category) {
            return;
        }

        // belong to category or any of the category children or any category children's children
        $childrenIds = $category->children->pluck('id')->push($categoryId);
        $childrenIds = $childrenIds->merge(
            $category
                ->children()
                ->with('children')
                ->get()
                ->pluck('children')
                ->flatten()
                ->pluck('id')
        );
        $childrenIds = $childrenIds->unique();
        $childrenIds = $childrenIds->values();
        $childrenIds = $childrenIds->toArray();

        $query->where(function ($query) use ($categoryId, $childrenIds) {
            $query->orWhere('category_id', $categoryId);
            $query->orWhereIn('category_id', $childrenIds);
        });
    }
}
