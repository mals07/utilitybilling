<?php

namespace App\Enums;

/**
 * Where an uploaded item stands.
 */
enum SyncItemStatus: string
{
    /** Uploaded; its batch isn't complete yet. */
    case Received = 'received';
    /** Saved to the live billing data. */
    case Applied = 'applied';
    /** Differs from the live data; waits on the Discrepancies page. */
    case Held = 'held';
    /** A supervisor rejected it: nothing was saved. */
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Applied => 'Applied',
            self::Held => 'Held for review',
            self::Rejected => 'Rejected',
        };
    }

    /**
     * Badge colors of the status.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Received => 'bg-panel text-label',
            self::Applied => 'bg-success-bg text-success',
            self::Held => 'bg-warning-bg text-warning',
            self::Rejected => 'bg-panel text-label',
        };
    }
}
