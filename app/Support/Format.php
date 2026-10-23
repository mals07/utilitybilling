<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Display formats shared by the pages.
 */
final class Format
{
    public const DEFAULT_TIMEZONE = 'Asia/Manila';

    /**
     * How every date reads on the pages and printouts: "October 5 2026".
     */
    public const DATE_FORMAT = 'F j Y';

    /**
     * "October 8 2026" for a moment in time, in the company's time zone.
     */
    public static function date(CarbonInterface $date, ?string $timezone): string
    {
        return $date->copy()->setTimezone($timezone ?? self::DEFAULT_TIMEZONE)->format(self::DATE_FORMAT);
    }

    /**
     * "October 8 2026 3:45 PM" for a moment in time, in the company's time zone.
     */
    public static function dateTime(CarbonInterface $date, ?string $timezone): string
    {
        return $date->copy()->setTimezone($timezone ?? self::DEFAULT_TIMEZONE)->format(self::DATE_FORMAT.' g:i A');
    }

    /**
     * "October 5 2026" for a calendar date (a date column, so no time zone shift).
     */
    public static function longDate(CarbonInterface $date): string
    {
        return $date->format(self::DATE_FORMAT);
    }

    /**
     * "Oct. 5 2026" for a calendar date where space is tight; May has no period since it isn't shortened.
     */
    public static function shortDate(CarbonInterface $date): string
    {
        return $date->format($date->month === 5 ? 'M j Y' : 'M. j Y');
    }

    /**
     * "Oct. 5 2026" for a moment in time, in the company's time zone.
     */
    public static function shortDateIn(CarbonInterface $date, ?string $timezone): string
    {
        return self::shortDate($date->copy()->setTimezone($timezone ?? self::DEFAULT_TIMEZONE));
    }

    /**
     * Today's date as "2026-10-08" in the company's time zone.
     */
    public static function todayIn(?string $timezone): string
    {
        return Carbon::now($timezone ?? self::DEFAULT_TIMEZONE)->toDateString();
    }

    /**
     * "2026-10-08" as "October 8 2026".
     */
    public static function isoDate(string $value): string
    {
        return self::longDate(Carbon::parse($value));
    }

    /**
     * "12,345"
     */
    public static function integer(int $value): string
    {
        return number_format($value);
    }

    /**
     * "15" or "1,234.5": a reading or cubic meters, without trailing zeros.
     */
    public static function quantity(string|float|int $value): string
    {
        $formatted = number_format((float) $value, 3);

        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }

    /**
     * "1,234.50" from a decimal string or number.
     */
    public static function amount(string|float|int $value): string
    {
        return number_format((float) $value, 2);
    }
}
