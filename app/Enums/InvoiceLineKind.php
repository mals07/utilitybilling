<?php

namespace App\Enums;

enum InvoiceLineKind: string
{
    /** Water consumption, one line per rate bracket. */
    case Water = 'water';
    /** A charge element of the customer's charge type, added (credit) or deducted (debit). */
    case Charge = 'charge';
    case WorkOrderFee = 'work_order_fee';
    /** An after-due charge for an overdue invoice. */
    case Surcharge = 'surcharge';
    /** A credit memo (negative), a debit memo (positive), or the reversal of a voided memo. */
    case Adjustment = 'adjustment';
    /** Tax on the taxable lines of the bill, e.g. "VAT (12%)". */
    case Tax = 'tax';
}
