<?php

namespace App\Models;

use App\Enums\ImportStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $filename
 */
class File extends BaseModel
{
    use BelongsToTenant, HasFactory;

    protected $table = 'imports';

    protected $fillable = [
        'account_id', 'filename', 'source_name', 'source_type', 'status',
        'file_fingerprint', 'total_rows', 'processed_rows', 'failed_rows',
        'error_message',
    ];

    protected function casts(): array
    {
        return ['status' => ImportStatus::class];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class, 'import_id');
    }
}
