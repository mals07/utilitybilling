<?php

namespace App\Support;

use App\Models\Company;
use Closure;
use Illuminate\Support\Facades\Auth;

/**
 * The company whose records queries may see: the signed-in user's, or the one a scheduled command
 * is working on. Code that names no company may not query company records at all, unless it says it
 * works across every company (acrossAll).
 */
final class CurrentCompany
{
    private static ?Company $override = null;

    private static bool $acrossAll = false;

    /**
     * Whether queries are limited to one company. A signed-in user without a company still counts;
     * their queries find nothing.
     */
    public static function isKnown(): bool
    {
        return self::$override !== null || Auth::user() !== null;
    }

    /**
     * Whether queries see every company's records, inside acrossAll().
     */
    public static function isAcrossAll(): bool
    {
        return self::$acrossAll;
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
        return self::within($company, false, $callback);
    }

    /**
     * Runs the callback with every company's records in reach, for the few places that must look
     * across companies, e.g. finding which company a sign-in belongs to.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public static function acrossAll(Closure $callback): mixed
    {
        return self::within(null, true, $callback);
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private static function within(?Company $company, bool $acrossAll, Closure $callback): mixed
    {
        [$previousCompany, $previousAcrossAll] = [self::$override, self::$acrossAll];
        [self::$override, self::$acrossAll] = [$company, $acrossAll];

        try {
            return $callback();
        } finally {
            [self::$override, self::$acrossAll] = [$previousCompany, $previousAcrossAll];
        }
    }
}
