<?php

namespace App\Models;

use App\Enums\FeeBilling;
use App\Enums\WorkOrderType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The fee prefilled when a company completes a work order of one type.
 */
#[Fillable(['company_id', 'order_type', 'amount', 'billing'])]
class WorkOrderFee extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_type' => WorkOrderType::class,
            'amount' => 'decimal:2',
            'billing' => FeeBilling::class,
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
