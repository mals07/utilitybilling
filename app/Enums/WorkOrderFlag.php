<?php

namespace App\Enums;

/**
 * Something a completed work order leaves for the office to follow up.
 */
enum WorkOrderFlag: string
{
    case BillingAdjustment = 'billing_adjustment';
    case LeakAdjustment = 'leak_adjustment';
    case QualityFail = 'quality_fail';
    case ReadingMismatch = 'reading_mismatch';

    public function label(): string
    {
        return match ($this) {
            self::BillingAdjustment => 'Billing adjustment needed',
            self::LeakAdjustment => 'Possible leak adjustment',
            self::QualityFail => 'Water quality failed',
            self::ReadingMismatch => 'Reading differs from disconnection',
        };
    }
}
