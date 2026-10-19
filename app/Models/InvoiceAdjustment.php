<?php

namespace App\Models;

use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentType;
use App\Models\Concerns\NumberedPerCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A credit memo (lowers what is owed on an invoice) or a debit memo (adds to it). Shows on the
 * invoice as a line; voiding it adds a reversing line rather than editing the invoice.
 */
#[Fillable([
    'company_id', 'type', 'memo_number', 'invoice_id', 'customer_id', 'reason_category', 'reason', 'amount',
    'issued_on', 'work_order_id', 'invoice_line_id', 'created_by_id', 'void_reason', 'voided_at', 'voided_by_id',
])]
class InvoiceAdjustment extends Model
{
    use NumberedPerCompany;

    public const NUMBER_COLUMN = 'memo_number';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AdjustmentType::class,
            'reason_category' => AdjustmentReason::class,
            'amount' => 'decimal:2',
            'issued_on' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * Credit and debit memos are numbered separately.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected static function numberSequence(array $attributes): array
    {
        $type = $attributes['type'];

        return ['type' => $type instanceof AdjustmentType ? $type->value : $type];
    }

    /**
     * "CM-000001"
     */
    public function label(): string
    {
        return $this->type->prefix().'-'.$this->memo_number;
    }

    public function isVoid(): bool
    {
        return $this->voided_at !== null;
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
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
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_id');
    }
}
