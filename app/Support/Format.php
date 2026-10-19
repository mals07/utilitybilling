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
     * "10/08/2026" in the company's time zone.
     */
    public static function date(CarbonInterface $date, ?string $timezone): string
    {
        return $date->copy()->setTimezone($timezone ?? self::DEFAULT_TIMEZONE)->format('m/d/Y');
    }

    /**
     * Today's date as "2026-10-08" in the company's time zone.
     */
    public static function todayIn(?string $timezone): string
    {
        return Carbon::now($timezone ?? self::DEFAULT_TIMEZONE)->toDateString();
    }

    /**
     * "2026-10-08" as "10/08/2026".
     */
    public static function isoDate(string $value): string
    {
        [$year, $month, $day] = explode('-', $value);

        return "{$month}/{$day}/{$year}";
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
