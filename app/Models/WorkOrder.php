<?php

namespace App\Models;

use App\Enums\WorkOrderStatus;
use App\Enums\WorkOrderType;
use App\Support\Format;
use Database\Factories\WorkOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable([
    'company_id', 'order_number', 'customer_id', 'order_type', 'meter_type_id', 'meter_number', 'serial_number',
    'description', 'assigned_to_id', 'status', 'requested_by_id', 'requested_at', 'completed_at', 'completed_by_id',
])]
class WorkOrder extends Model
{
    /** @use HasFactory<WorkOrderFactory> */
    use HasFactory;

    /**
     * Days an order may stay In Progress after it was requested before it is overdue.
     */
    public const OVERDUE_AFTER_DAYS = 5;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_type' => WorkOrderType::class,
            'status' => WorkOrderStatus::class,
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
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
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<MeterType, $this>
     */
    public function meterType(): BelongsTo
    {
        return $this->belongsTo(MeterType::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_id');
    }

    /**
     * Orders requested before this moment are overdue while In Progress. Days are counted in the
     * company's time zone: an order requested on Oct 1 is overdue from Oct 7, more than five days later.
     */
    public static function overdueCutoff(?string $timezone): Carbon
    {
        return Carbon::now($timezone ?? Format::DEFAULT_TIMEZONE)->startOfDay()->subDays(self::OVERDUE_AFTER_DAYS)->utc();
    }

    /**
     * Still In Progress more than five days after it was requested.
     */
    public function isOverdue(?string $timezone): bool
    {
        return $this->status === WorkOrderStatus::InProgress && $this->requested_at->lt(self::overdueCutoff($timezone));
    }
}
