<?php

namespace App\Enums;

/**
 * What a meter reader's device uploaded.
 */
enum SyncItemType: string
{
    /** A meter reading, with the bill printed for it when there is one. */
    case Reading = 'reading';
    /** An invoice number the device gave up, e.g. when the printer jammed and the reader cancelled the bill. */
    case SkippedNumber = 'skipped_number';
}
