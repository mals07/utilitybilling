<?php

namespace App\Enums;

enum InvoiceKind: string
{
    /** The monthly water bill from a billing run. */
    case Monthly = 'monthly';
    /** A work order fee billed on its own, e.g. a New Installation fee paid upfront. */
    case WorkOrder = 'work_order';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly Bill',
            self::WorkOrder => 'Work Order Fee',
        };
    }
}
