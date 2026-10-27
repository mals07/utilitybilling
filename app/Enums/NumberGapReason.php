<?php

namespace App\Enums;

/**
 * Why a reserved invoice number never became an invoice.
 */
enum NumberGapReason: string
{
    /** The reader cancelled the bill on the device, e.g. the printer jammed. */
    case Skipped = 'skipped';
    /** Still unused when the device handed its block back. */
    case Unused = 'unused';
    /** The bill was printed but a supervisor rejected its reading. */
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Skipped => 'Skipped on the device',
            self::Unused => 'Not used',
            self::Rejected => 'Printed, then rejected',
        };
    }
}
