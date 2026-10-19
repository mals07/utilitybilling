<?php

namespace App\Models;

use App\Enums\InvoiceLineKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an invoice; deductions such as discounts have a negative amount.
 */
#[Fillable(['invoice_id', 'kind', 'description', 'quantity', 'rate', 'amount', 'charge_element_id', 'work_order_id', 'source_invoice_id', 'adjustment_id', 'sort'])]
class InvoiceLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => InvoiceLineKind::class,
            'quantity' => 'decimal:3',
            'rate' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * The overdue invoice a surcharge line is for.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function sourceInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'source_invoice_id');
    }
}
