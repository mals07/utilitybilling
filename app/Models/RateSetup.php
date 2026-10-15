<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Minimum consumption and how defective meters are billed, one per rate type.
 */
#[Fillable(['rate_type_id', 'minimum_consumption', 'defective_uses_minimum_consumption', 'defective_uses_three_month_average'])]
class RateSetup extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'minimum_consumption' => 'integer',
            'defective_uses_minimum_consumption' => 'boolean',
            'defective_uses_three_month_average' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<RateType, $this>
     */
    public function rateType(): BelongsTo
    {
        return $this->belongsTo(RateType::class);
    }
}
