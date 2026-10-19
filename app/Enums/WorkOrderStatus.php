<?php

namespace App\Enums;

enum WorkOrderStatus: string
{
    case InProgress = 'in_progress';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'In Progress',
            self::OnHold => 'On Hold',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Text color of the status in the work orders table.
     */
    public function colorClass(): string
    {
        return match ($this) {
            self::InProgress => 'text-ink-soft',
            self::OnHold => 'text-warning',
            self::Completed => 'text-success',
            self::Cancelled => 'text-muted',
        };
    }

    /**
     * Still to be done: it can be edited, put on hold, completed or cancelled. Completed and
     * Cancelled orders are final.
     */
    public function isOpen(): bool
    {
        return $this === self::InProgress || $this === self::OnHold;
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [self::InProgress->value, self::OnHold->value];
    }
}
