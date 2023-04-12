<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends BaseModel
{
    use HasFactory;

    protected $fillable = ['account_number', 'transaction_date', 'description', 'amount'];

    public $timestamps = ['transaction_date'];
}
