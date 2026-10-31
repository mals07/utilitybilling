<?php

namespace App\Enums;

use App\Support\Format;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * What a report column holds, which decides how its values read on screen, on paper and in a CSV file.
 *
 * Amounts are whole cents, dates "2026-10-05", quantities cubic meters and percents a number such as 87.5.
 */
enum ReportColumnType: string
{
    case Text = 'text';
    case Date = 'date';
    case Amount = 'amount';
    case Quantity = 'quantity';
    case Count = 'count';
    case Percent = 'percent';

    /**
     * Whether the column's values line up on the right, as numbers do.
     */
    public function isNumeric(): bool
    {
        return ! in_array($this, [self::Text, self::Date], true);
    }

    public function format(mixed $value): string
    {
        if ($value === null || $value === '') {
            return $this->isNumeric() ? '—' : '';
        }

        return match ($this) {
            self::Text => (string) $value,
            self::Date => Format::shortDate(Carbon::parse($value)),
            self::Amount => Format::amount(Money::fromCents((int) $value)),
            self::Quantity => Format::quantity($value),
            self::Count => Format::integer((int) $value),
            self::Percent => number_format((float) $value, 1).'%',
        };
    }

    /**
     * Orders two filled-in values of the column: numbers by size, text in natural order ignoring case
     * ("Zone 2" before "Zone 10"), dates by day.
     */
    public function compare(mixed $a, mixed $b): int
    {
        return $this->isNumeric() ? (float) $a <=> (float) $b : strnatcasecmp((string) $a, (string) $b);
    }

    /**
     * The value as a spreadsheet cell: plain numbers without thousands separators, ISO dates.
     */
    public function csv(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return match ($this) {
            self::Amount => Money::fromCents((int) $value),
            self::Quantity => rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.'),
            self::Percent => number_format((float) $value, 1, '.', ''),
            default => (string) $value,
        };
    }
}
