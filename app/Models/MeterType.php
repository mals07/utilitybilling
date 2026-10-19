<?php

namespace App\Models;

use Database\Factories\MeterTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'name', 'is_active', 'dial_digits'])]
class MeterType extends CompanyLookup
{
    /** @use HasFactory<MeterTypeFactory> */
    use HasFactory;

    /**
     * Fewest and most dials a meter type may have.
     */
    public const MIN_DIGITS = 3;

    public const MAX_DIGITS = 9;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [...parent::casts(), 'dial_digits' => 'integer'];
    }

    /**
     * The reading after which the meter starts again at 0 (9999 for 4 dials), or null when the dials aren't set.
     */
    public function highestReading(): ?int
    {
        return $this->dial_digits === null ? null : 10 ** $this->dial_digits - 1;
    }

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
