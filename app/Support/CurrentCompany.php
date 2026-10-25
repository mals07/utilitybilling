<?php

namespace App\Support;

use App\Models\Company;
use Closure;
use Illuminate\Support\Facades\Auth;

/**
 * The company whose records queries may see: the signed-in user's, or the one a scheduled command
 * is working on. Guests, commands and jobs that name no company see every company's records.
 */
final class CurrentCompany
{
    private static ?Company $override = null;

    /**
     * Whether queries are limited to one company. A signed-in user without a company still counts;
     * their queries find nothing.
     */
    public static function isKnown(): bool
    {
        return self::$override !== null || Auth::user() !== null;
    }

    public static function id(): ?int
    {
        return self::$override?->id ?? Auth::user()?->company_id;
    }

    /**
     * Runs the callback as the given company, e.g. for each company in a scheduled command.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public static function as(Company $company, Closure $callback): mixed
    {
        $previous = self::$override;
        self::$override = $company;

        try {
            return $callback();
        } finally {
            self::$override = $previous;
        }
    }
}
