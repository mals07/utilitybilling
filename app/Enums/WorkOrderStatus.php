<?php

namespace App\Enums;

enum WorkOrderStatus: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'In Progress',
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
            self::Completed => 'text-success',
            self::Cancelled => 'text-muted',
        };
    }

    /**
     * @return array<string, string> value => label
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $status): array => [$status->value => $status->label()])->all();
    }
}
