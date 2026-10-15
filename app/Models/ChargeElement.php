<?php

namespace App\Models;

use App\Enums\DistributionType;
use Database\Factories\ChargeElementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'name', 'distribution_type', 'is_after_due'])]
class ChargeElement extends Model
{
    /** @use HasFactory<ChargeElementFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'distribution_type' => DistributionType::class,
            // Charged only after the due date, e.g. a surcharge.
            'is_after_due' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<ChargeTypeElement, $this>
     */
    public function chargeTypes(): HasMany
    {
        return $this->hasMany(ChargeTypeElement::class);
    }
}
