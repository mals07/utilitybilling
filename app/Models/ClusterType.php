<?php

namespace App\Models;

use Database\Factories\ClusterTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClusterType extends CompanyLookup
{
    /** @use HasFactory<ClusterTypeFactory> */
    use HasFactory;

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
