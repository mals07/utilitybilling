<?php

namespace App\Models;

use App\Enums\DisconnectionNoticeStatus;
use App\Models\Concerns\NumberedPerCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A notice of disconnection for non-payment, with what the customer owed when it was issued.
 */
#[Fillable([
    'company_id', 'notice_number', 'customer_id', 'status', 'overdue_bills', 'overdue_amount', 'oldest_due_date',
    'issued_on', 'deadline', 'emailed_at', 'printed_at', 'work_order_id', 'closed_at', 'close_reason',
    'issued_by_id', 'closed_by_id',
])]
class DisconnectionNotice extends Model
{
    use NumberedPerCompany;

    public const NUMBER_COLUMN = 'notice_number';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DisconnectionNoticeStatus::class,
            'overdue_bills' => 'integer',
            'overdue_amount' => 'decimal:2',
            'oldest_due_date' => 'date',
            'issued_on' => 'date',
            'deadline' => 'date',
            'emailed_at' => 'datetime',
            'printed_at' => 'datetime',
            'closed_at' => 'datetime',
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
     * @return BelongsTo<WorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    /**
     * Pending with its deadline passed ($today is "YYYY-MM-DD" in the company's time zone).
     */
    public function isDue(string $today): bool
    {
        return $this->status === DisconnectionNoticeStatus::Pending && $this->deadline->toDateString() < $today;
    }
}
