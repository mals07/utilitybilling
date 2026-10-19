<?php

namespace App\Enums;

/**
 * How a work order fee is billed.
 */
enum FeeBilling: string
{
    /** Its own invoice, created when the work order is completed. */
    case SeparateInvoice = 'separate_invoice';
    /** A line on the customer's next monthly bill. */
    case NextBill = 'next_bill';

    public function label(): string
    {
        return match ($this) {
            self::SeparateInvoice => 'Separate invoice',
            self::NextBill => 'Next monthly bill',
        };
    }

    /**
     * Fees paid upfront get their own invoice unless the company says otherwise.
     */
    public static function defaultFor(WorkOrderType $type): self
    {
        return in_array($type, [WorkOrderType::NewInstallation, WorkOrderType::Reconnection], true)
            ? self::SeparateInvoice
            : self::NextBill;
    }
}
