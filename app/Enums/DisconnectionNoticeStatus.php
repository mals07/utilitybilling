<?php

namespace App\Enums;

/**
 * Where a notice of disconnection is: waiting for payment, with a crew, or closed.
 */
enum DisconnectionNoticeStatus: string
{
    /** Issued; the customer has until the deadline to pay. */
    case Pending = 'pending';
    /** The deadline passed unpaid and a Disconnection work order was created. */
    case Scheduled = 'scheduled';
    /** The Disconnection work order was completed. */
    case Disconnected = 'disconnected';
    /** The customer paid enough that they no longer qualify for disconnection. */
    case Settled = 'settled';
    /** Withdrawn by staff, or its work order was cancelled. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Notice sent',
            self::Scheduled => 'Work order created',
            self::Disconnected => 'Disconnected',
            self::Settled => 'Settled',
            self::Cancelled => 'Cancelled',
        };
    }

    public function colorClass(): string
    {
        return match ($this) {
            self::Pending => 'text-warning',
            self::Scheduled => 'text-danger',
            self::Disconnected => 'text-danger',
            self::Settled => 'text-success',
            self::Cancelled => 'text-muted',
        };
    }

    /**
     * Still in progress: the customer may yet be disconnected.
     */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Scheduled;
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [self::Pending->value, self::Scheduled->value];
    }
}
