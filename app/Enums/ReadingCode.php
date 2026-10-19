<?php

namespace App\Enums;

/**
 * How a monthly meter reading was taken.
 */
enum ReadingCode: string
{
    case Normal = 'normal';
    /** The meter couldn't be reached (locked gate, dog, flooded box): usage is estimated. */
    case NoAccess = 'no_access';
    /** The meter is broken or unreadable: usage is estimated. */
    case Defective = 'defective';
    /** The meter wrapped past its highest reading (e.g. 9999) back to 0. */
    case Rollover = 'rollover';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::NoAccess => 'No access',
            self::Defective => 'Defective meter',
            self::Rollover => 'Meter rolled over',
        };
    }

    /**
     * Whether usage is estimated instead of read from the meter.
     */
    public function isEstimated(): bool
    {
        return in_array($this, [self::NoAccess, self::Defective], true);
    }
}
