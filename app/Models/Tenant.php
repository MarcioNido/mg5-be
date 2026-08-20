<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends \Spatie\Multitenancy\Models\Tenant
{
    protected $guarded = ['id'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(Rule::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(Balance::class);
    }
}
