<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class File extends BaseModel
{
    use HasFactory;

    protected $fillable=['filename'];
}
