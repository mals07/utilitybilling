<?php

namespace App\Support;

/**
 * Amounts are worked out in whole cents so sums never drift; they are stored as "1234.50".
 */
final class Money
{
    public static function toCents(string|int|float|null $amount): int
    {
        return (int) round(((float) ($amount ?? 0)) * 100);
    }

    public static function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * $percent of $cents, rounded half up to the cent.
     */
    public static function percentOf(int $cents, string|float $percent): int
    {
        return (int) round($cents * (float) $percent / 100);
    }

    /**
     * $quantity × $rate in cents, rounded to the cent.
     */
    public static function times(string|float $quantity, string|float $rate): int
    {
        return (int) round((float) $quantity * (float) $rate * 100);
    }
}
