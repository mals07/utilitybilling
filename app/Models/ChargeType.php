<?php

namespace App\Models;

use Database\Factories\ChargeTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'name', 'description'])]
class ChargeType extends Model
{
    /** @use HasFactory<ChargeTypeFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The charge elements this charge type applies, with their values.
     *
     * @return HasMany<ChargeTypeElement, $this>
     */
    public function elements(): HasMany
    {
        return $this->hasMany(ChargeTypeElement::class);
    }
}
