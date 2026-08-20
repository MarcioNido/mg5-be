<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property string $filename
 */
class File extends BaseModel
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['filename', 'status'];
}
