<?php

namespace App\Models;

use Database\Factories\MeterTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeterType extends CompanyLookup
{
    /** @use HasFactory<MeterTypeFactory> */
    use HasFactory;

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
