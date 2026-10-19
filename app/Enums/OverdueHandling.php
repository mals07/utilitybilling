<?php

namespace App\Enums;

/**
 * What happens to a bill not paid by its due date. Either way, after-due charge elements
 * (e.g. Surcharge) are applied once per overdue invoice.
 */
enum OverdueHandling: string
{
    /** The unpaid amount shows as previous balance on the next bill, which carries the surcharge. */
    case NextBill = 'next_bill';
    /** The surcharge is added to the overdue invoice itself. */
    case SameInvoice = 'same_invoice';

    public function label(): string
    {
        return match ($this) {
            self::NextBill => 'Carry over to the next bill, with the surcharge on the next bill',
            self::SameInvoice => 'Add the surcharge to the overdue invoice itself',
        };
    }
}
