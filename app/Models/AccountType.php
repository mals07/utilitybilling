<?php

namespace App\Models;

use Database\Factories\AccountTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountType extends CompanyLookup
{
    /** @use HasFactory<AccountTypeFactory> */
    use HasFactory;

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
