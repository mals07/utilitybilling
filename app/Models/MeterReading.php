<?php

namespace App\Models;

use App\Enums\MeterReadingStatus;
use App\Enums\ReadingCode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's reading for one billing period, entered on the Meter Readings page and billed on Generate Bills.
 */
#[Fillable([
    'company_id', 'customer_id', 'meter_number', 'billing_period', 'status', 'reading_code', 'previous_reading',
    'present_reading', 'consumption', 'actual_consumption', 'replaced_meter_consumption', 'estimate_deducted',
    'is_defective', 'review_reason', 'notes', 'read_on', 'invoice_id', 'created_by_id', 'confirmed_by_id', 'confirmed_at',
])]
class MeterReading extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MeterReadingStatus::class,
            'reading_code' => ReadingCode::class,
            'previous_reading' => 'decimal:3',
            'present_reading' => 'decimal:3',
            'consumption' => 'decimal:3',
            'actual_consumption' => 'decimal:3',
            'replaced_meter_consumption' => 'decimal:3',
            'estimate_deducted' => 'decimal:3',
            'is_defective' => 'boolean',
            'read_on' => 'date',
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * Usage measured or estimated, before the rate's minimum consumption. Readings saved before this
     * was recorded only have the billed consumption.
     */
    public function usage(): float
    {
        return (float) ($this->actual_consumption ?? $this->consumption);
    }

    public function isBilled(): bool
    {
        return $this->invoice_id !== null;
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_id');
    }
}
