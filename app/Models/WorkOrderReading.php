<?php

namespace App\Models;

use App\Enums\ReadingKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A meter reading taken while completing a work order. The Meter Readings page uses it later
 * and sets used_at.
 */
#[Fillable(['company_id', 'customer_id', 'work_order_id', 'meter_number', 'kind', 'reading', 'read_on', 'used_at'])]
class WorkOrderReading extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ReadingKind::class,
            'reading' => 'decimal:3',
            'read_on' => 'date',
            'used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
