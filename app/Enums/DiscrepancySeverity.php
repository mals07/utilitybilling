<?php

namespace App\Enums;

/**
 * Whether a difference found on sync stops the item from being applied.
 */
enum DiscrepancySeverity: string
{
    /** The item is held until a supervisor resolves it. */
    case Blocking = 'blocking';
    /** The item is applied; the difference is only logged. */
    case Info = 'info';
}
