<?php

namespace App\Models;

use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Models\Concerns\NumberedPerCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bill: the monthly water bill or a work order fee. Never edited after it is issued; mistakes are voided.
 */
#[Fillable([
    'company_id', 'invoice_number', 'customer_id', 'kind', 'billing_period', 'meter_reading_id', 'work_order_id',
    'invoice_date', 'due_date', 'total_amount', 'amount_paid', 'balance', 'previous_balance', 'status',
    'surcharged_at', 'surcharged_on_invoice_id', 'void_reason', 'voided_at', 'voided_by_id', 'created_by_id',
    'tax_name', 'tax_rate', 'tax_inclusive', 'taxable_amount', 'tax_amount', 'tax_exempt_amount',
])]
class Invoice extends Model
{
    use NumberedPerCompany;

    public const NUMBER_COLUMN = 'invoice_number';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => InvoiceKind::class,
            'status' => InvoiceStatus::class,
            'invoice_date' => 'date',
            'due_date' => 'date',
            'total_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'balance' => 'decimal:2',
            'previous_balance' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_inclusive' => 'boolean',
            'taxable_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'tax_exempt_amount' => 'decimal:2',
            'surcharged_at' => 'datetime',
            'voided_at' => 'datetime',
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
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * @return BelongsTo<MeterReading, $this>
     */
    public function meterReading(): BelongsTo
    {
        return $this->belongsTo(MeterReading::class);
    }

    /**
     * @return BelongsTo<WorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * Credit and debit memos issued against this invoice, voided ones included.
     *
     * @return HasMany<InvoiceAdjustment, $this>
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(InvoiceAdjustment::class)->orderBy('id');
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * Still has a balance after its due date ($today is "YYYY-MM-DD" in the company's time zone).
     */
    public function isOverdue(string $today): bool
    {
        return $this->status->isOpen() && $this->due_date->toDateString() < $today;
    }

    /**
     * "Overdue" for an open invoice past its due date, otherwise the stored status.
     */
    public function statusLabel(string $today): string
    {
        return $this->isOverdue($today) ? 'Overdue' : $this->status->label();
    }

    public function statusColorClass(string $today): string
    {
        return $this->isOverdue($today) ? 'font-semibold text-danger' : $this->status->colorClass();
    }

    /**
     * "October 2026" for a monthly bill.
     */
    public function periodLabel(): ?string
    {
        return $this->billing_period === null ? null : date('F Y', strtotime($this->billing_period.'-01'));
    }
}
