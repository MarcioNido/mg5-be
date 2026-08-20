<?php

namespace App\Models;

use App\Enums\MatchSuggestionStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchSuggestion extends Model
{
    use BelongsToTenant;

    protected $fillable = ['import_row_id', 'pending_transaction_id', 'status', 'confidence'];

    protected function casts(): array
    {
        return ['status' => MatchSuggestionStatus::class, 'confidence' => 'decimal:4'];
    }

    public function importRow(): BelongsTo
    {
        return $this->belongsTo(ImportRow::class);
    }

    public function pendingTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'pending_transaction_id');
    }
}
