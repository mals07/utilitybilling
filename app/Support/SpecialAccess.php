<?php

namespace App\Support;

use App\Enums\Access;
use DateTimeImmutable;

/**
 * Rules for special access rows: temporary access to a page outside the user's role.
 * Dates are "YYYY-MM-DD" in the company's time zone, so they compare as strings.
 *
 * A row is ['id' => ?int, 'page' => string, 'flags' => [access => bool], 'startDate' => string, 'endDate' => string];
 * rows added in the modal and not saved yet have no id.
 */
final class SpecialAccess
{
    /**
     * "upcoming", "active" or "ended".
     *
     * @param  array{startDate: string, endDate: string}  $row
     */
    public static function status(array $row, string $today): string
    {
        return match (true) {
            $today < $row['startDate'] => 'upcoming',
            $today > $row['endDate'] => 'ended',
            default => 'active',
        };
    }

    /**
     * Whether two date ranges share at least one day.
     *
     * @param  array{startDate: string, endDate: string}  $a
     * @param  array{startDate: string, endDate: string}  $b
     */
    public static function overlaps(array $a, array $b): bool
    {
        return $a['startDate'] <= $b['endDate'] && $b['startDate'] <= $a['endDate'];
    }

    /**
     * A real calendar date written as "YYYY-MM-DD".
     */
    public static function isIsoDate(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /**
     * Errors for a row about to be added, keyed by field, or [] when it can be added.
     *
     * @param  array{page: string, flags: array<string, bool>, startDate: string, endDate: string}  $row
     * @param  list<array{page: string, startDate: string, endDate: string}>  $others  Rows already in the list.
     * @param  list<string>  $rolePages  Pages the main user role already opens.
     * @return array<string, string>
     */
    public static function checkNew(array $row, array $others, array $rolePages, string $pageLabel, string $today): array
    {
        $errors = [];

        if ($row['page'] === '') {
            $errors['accessPage'] = 'Select a page or form.';
        } elseif (in_array($row['page'], $rolePages, true)) {
            $errors['accessPage'] = "{$pageLabel} is already part of the main user role.";
        }

        if (! in_array(true, $row['flags'], true)) {
            $errors['accessFlags'] = 'Check at least one access.';
        }

        if ($row['startDate'] === '') {
            $errors['startDate'] = 'Select a start date.';
        } elseif (! self::isIsoDate($row['startDate'])) {
            $errors['startDate'] = 'Enter a valid start date.';
        } elseif ($row['startDate'] < $today) {
            $errors['startDate'] = "The start date can't be in the past.";
        }

        if ($row['endDate'] === '') {
            $errors['endDate'] = 'Select an end date.';
        } elseif (! self::isIsoDate($row['endDate'])) {
            $errors['endDate'] = 'Enter a valid end date.';
        } elseif (! isset($errors['startDate']) && $row['endDate'] < $row['startDate']) {
            $errors['endDate'] = "The end date can't be before the start date.";
        }

        if ($errors === []) {
            foreach ($others as $other) {
                if ($other['page'] === $row['page'] && self::overlaps($row, $other)) {
                    $errors['accessPage'] = "{$pageLabel} already has special access on some of these dates.";
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * "Create, Edit, View" — the Users modal calls Add "Create", as in its design.
     *
     * @param  array<string, bool>  $flags
     */
    public static function describe(array $flags): string
    {
        return collect(Access::cases())
            ->filter(fn (Access $access): bool => (bool) ($flags[$access->value] ?? false))
            ->map(fn (Access $access): string => $access->specialAccessLabel())
            ->implode(', ');
    }
}
