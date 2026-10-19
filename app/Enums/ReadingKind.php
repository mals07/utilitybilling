<?php

namespace App\Enums;

/**
 * Why a meter reading was taken during a work order.
 */
enum ReadingKind: string
{
    /** First reading of a newly installed meter. */
    case Initial = 'initial';
    /** Last reading of a meter being disconnected, removed or handed over. */
    case Final = 'final';
    case Reconnection = 'reconnection';
    case Before = 'before';
    case After = 'after';
    case Verified = 'verified';

    public function label(): string
    {
        return match ($this) {
            self::Initial => 'Initial',
            self::Final => 'Final',
            self::Reconnection => 'At reconnection',
            self::Before => 'Before work',
            self::After => 'After work',
            self::Verified => 'Verified',
        };
    }
}
