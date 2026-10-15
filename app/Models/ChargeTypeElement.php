<?php

namespace App\Models;

use App\Enums\ChargeValueType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A charge element applied to a charge type, as a fixed amount or a percentage.
 */
#[Fillable(['charge_type_id', 'charge_element_id', 'value_type', 'value'])]
class ChargeTypeElement extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value_type' => ChargeValueType::class,
            'value' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<ChargeType, $this>
     */
    public function chargeType(): BelongsTo
    {
        return $this->belongsTo(ChargeType::class);
    }

    /**
     * @return BelongsTo<ChargeElement, $this>
     */
    public function chargeElement(): BelongsTo
    {
        return $this->belongsTo(ChargeElement::class);
    }
}
