<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $account_number
 */
class Account extends Model
{
    use HasFactory;

    protected $primaryKey = "account_number";
    protected $keyType = "string";

    public function transactions(): HasMany
    {
        return $this->hasMany(
            Transaction::class,
            "account_number",
            "account_number"
        );
    }
}
