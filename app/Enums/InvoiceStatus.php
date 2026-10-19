<?php

namespace App\Enums;

/**
 * Stored status of an invoice. "Overdue" isn't stored: it is an Unpaid or Partially Paid
 * invoice past its due date (see Invoice::isOverdue()).
 */
enum InvoiceStatus: string
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::PartiallyPaid => 'Partially Paid',
            self::Paid => 'Paid',
            self::Void => 'Void',
        };
    }

    public function colorClass(): string
    {
        return match ($this) {
            self::Unpaid => 'text-ink-soft',
            self::PartiallyPaid => 'text-warning',
            self::Paid => 'text-success',
            self::Void => 'text-muted',
        };
    }

    /**
     * Still has a balance to pay.
     */
    public function isOpen(): bool
    {
        return $this === self::Unpaid || $this === self::PartiallyPaid;
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [self::Unpaid->value, self::PartiallyPaid->value];
    }
}
