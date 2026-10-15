<?php

namespace App\Enums;

enum CustomerStatus: string
{
    case Pending = 'pending';
    case Connected = 'connected';
    case Disconnected = 'disconnected';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Text color of the status in the customers table.
     */
    public function colorClass(): string
    {
        return match ($this) {
            self::Pending => 'text-muted',
            self::Connected => 'text-success',
            self::Disconnected => 'text-danger',
        };
    }
}
