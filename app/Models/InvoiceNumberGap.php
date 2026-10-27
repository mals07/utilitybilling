<?php

namespace App\Models;

use App\Enums\NumberGapReason;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reserved invoice number that never became an invoice, and why.
 */
#[Fillable(['company_id', 'invoice_number_block_id', 'invoice_number', 'reason', 'note'])]
class InvoiceNumberGap extends Model
{
    use BelongsToCompany;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => NumberGapReason::class,
        ];
    }

    /**
     * @return BelongsTo<InvoiceNumberBlock, $this>
     */
    public function block(): BelongsTo
    {
        return $this->belongsTo(InvoiceNumberBlock::class, 'invoice_number_block_id');
    }
}
