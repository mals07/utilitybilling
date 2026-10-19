<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's reading for one billing period, entered in a billing run.
 */
#[Fillable([
    'company_id', 'customer_id', 'meter_number', 'billing_period', 'previous_reading', 'present_reading',
    'consumption', 'replaced_meter_consumption', 'is_defective', 'read_on', 'invoice_id', 'created_by_id',
])]
class MeterReading extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_reading' => 'decimal:3',
            'present_reading' => 'decimal:3',
            'consumption' => 'decimal:3',
            'replaced_meter_consumption' => 'decimal:3',
            'is_defective' => 'boolean',
            'read_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
