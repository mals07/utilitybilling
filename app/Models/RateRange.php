<?php

namespace App\Models;

use Database\Factories\RateRangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Consumption ranges are contiguous: each starts at the previous range's end + 1.
 */
#[Fillable(['rate_type_id', 'range_from', 'range_to', 'rate', 'description'])]
class RateRange extends Model
{
    /** @use HasFactory<RateRangeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'range_from' => 'integer',
            'range_to' => 'integer',
            'rate' => 'decimal:2',
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
