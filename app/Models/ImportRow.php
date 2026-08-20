<?php

namespace App\Models;

use App\Enums\ImportRowStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportRow extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'import_id', 'account_id', 'transaction_id', 'imported_movement_id',
        'line_number', 'raw_payload', 'normalized_payload', 'fingerprint',
        'occurrence', 'status', 'error_message',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'normalized_payload' => 'array',
            'status' => ImportRowStatus::class,
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(File::class, 'import_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function suggestions(): HasMany
    {
        return $this->hasMany(MatchSuggestion::class);
    }

    public function importedMovement(): BelongsTo
    {
        return $this->belongsTo(ImportedMovement::class);
    }
}
