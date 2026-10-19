<?php

namespace App\Models;

use App\Enums\WorkOrderFlag;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkOrderType;
use App\Models\Concerns\NumberedPerCompany;
use App\Support\Format;
use Database\Factories\WorkOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable([
    'company_id', 'order_number', 'customer_id', 'parent_work_order_id', 'order_type', 'meter_type_id', 'meter_number',
    'serial_number', 'description', 'assigned_to_id', 'status', 'hold_reason', 'cancel_reason', 'requested_by_id',
    'requested_at', 'completed_at', 'completed_by_id', 'completion_data', 'review_flag', 'fee_amount', 'fee_billed_at',
    'fee_invoice_id', 'flag_resolved_at', 'flag_resolution',
])]
class WorkOrder extends Model
{
    /** @use HasFactory<WorkOrderFactory> */
    use HasFactory, NumberedPerCompany;

    /**
     * Days an order may stay open after it was requested before it is overdue.
     */
    public const OVERDUE_AFTER_DAYS = 5;

    public const NUMBER_COLUMN = 'order_number';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_type' => WorkOrderType::class,
            'status' => WorkOrderStatus::class,
            'review_flag' => WorkOrderFlag::class,
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
            'completion_data' => 'array',
            'fee_amount' => 'decimal:2',
            'fee_billed_at' => 'datetime',
            'flag_resolved_at' => 'datetime',
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
     * The order whose completion created this one.
     *
     * @return BelongsTo<WorkOrder, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'parent_work_order_id');
    }

    /**
     * Orders created by completing this one.
     *
     * @return HasMany<WorkOrder, $this>
     */
    public function followUps(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'parent_work_order_id');
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
     * @return HasMany<WorkOrderReading, $this>
     */
    public function readings(): HasMany
    {
        return $this->hasMany(WorkOrderReading::class);
    }

    /**
     * @return HasMany<WorkOrderPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(WorkOrderPhoto::class);
    }

    /**
     * @return HasMany<WorkOrderActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(WorkOrderActivity::class);
    }

    /**
     * The invoice the fee was billed on, its own or a monthly bill.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function feeInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'fee_invoice_id');
    }

    /**
     * Adds an entry to the order's timeline.
     */
    public function log(?User $user, string $event, string $description): void
    {
        $this->activities()->create(['user_id' => $user?->id, 'event' => $event, 'description' => $description]);
    }

    /**
     * Orders requested before this moment are overdue while open. Days are counted in the
     * company's time zone: an order requested on Oct 1 is overdue from Oct 7, more than five days later.
     */
    public static function overdueCutoff(?string $timezone): Carbon
    {
        return Carbon::now($timezone ?? Format::DEFAULT_TIMEZONE)->startOfDay()->subDays(self::OVERDUE_AFTER_DAYS)->utc();
    }

    /**
     * Still open (In Progress or On Hold) more than five days after it was requested.
     */
    public function isOverdue(?string $timezone): bool
    {
        return $this->status->isOpen() && $this->requested_at->lt(self::overdueCutoff($timezone));
    }
}
