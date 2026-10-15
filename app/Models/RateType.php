<?php

namespace App\Models;

use Database\Factories\RateTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['company_id', 'name', 'description'])]
class RateType extends Model
{
    /** @use HasFactory<RateTypeFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Minimum consumption and defective computation.
     *
     * @return HasOne<RateSetup, $this>
     */
    public function setup(): HasOne
    {
        return $this->hasOne(RateSetup::class);
    }

    /**
     * Consumption ranges, lowest first.
     *
     * @return HasMany<RateRange, $this>
     */
    public function ranges(): HasMany
    {
        return $this->hasMany(RateRange::class)->orderBy('range_from');
    }
}
