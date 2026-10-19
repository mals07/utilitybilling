<?php

namespace App\Enums;

/**
 * Where a monthly meter reading stands: saved readings are billed on Generate Bills once they are Ready.
 */
enum MeterReadingStatus: string
{
    /** Looks wrong (e.g. far above the usual usage): confirm it or read the meter again. */
    case Review = 'review';
    case Ready = 'ready';
    case Billed = 'billed';

    public function label(): string
    {
        return match ($this) {
            self::Review => 'Needs review',
            self::Ready => 'Ready to bill',
            self::Billed => 'Billed',
        };
    }

    /**
     * Badge colors of the status.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Review => 'bg-warning-bg text-warning',
            self::Ready => 'bg-success-bg text-success',
            self::Billed => 'bg-panel text-label',
        };
    }
}
