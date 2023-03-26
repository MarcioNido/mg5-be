<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property string $filename
 */
class File extends BaseModel
{
    use HasFactory;

    protected $fillable=['filename', 'status'];
}
