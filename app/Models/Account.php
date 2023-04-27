<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $account_number
 */
class Account extends Model
{
    use HasFactory;

    protected $primaryKey = "account_number";
    protected $keyType = "string";
}
