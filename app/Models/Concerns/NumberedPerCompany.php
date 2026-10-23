<?php

namespace App\Models\Concerns;

use App\Models\Company;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Records numbered per company in the order they are created ("000001", "000002", ...), like work
 * orders, invoices and official receipts. The model names the column with NUMBER_COLUMN.
 *
 * The last number given out is kept in number_sequences. Taking a number locks the company's
 * counter until the surrounding transaction ends, so records created at the same moment get
 * consecutive numbers instead of reading every number issued so far.
 */
trait NumberedPerCompany
{
    /**
     * Creates the record with the next number in the company's sequence. A record numbered some
     * other way (an import, a factory) may already hold that number; the unique index rejects it, and
     * the counter is caught up to the highest number in the table before trying again.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function createNumbered(Company $company, array $attributes): static
    {
        return DB::transaction(function () use ($company, $attributes): static {
            for ($attempt = 1; ; $attempt++) {
                $number = self::takeNextNumber($company, $attributes, catchUp: $attempt > 1);

                try {
                    return static::query()->create([
                        ...$attributes,
                        'company_id' => $company->id,
                        static::NUMBER_COLUMN => str_pad((string) $number, 6, '0', STR_PAD_LEFT),
                    ]);
                } catch (UniqueConstraintViolationException $exception) {
                    if ($attempt >= 3) {
                        throw $exception;
                    }
                }
            }
        });
    }

    /**
     * Columns that split the company's numbers into separate sequences, e.g. credit and debit memos.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected static function numberSequence(array $attributes): array
    {
        return [];
    }

    /**
     * Advances the company's counter for this sequence and returns the new number. The counter
     * starts from the highest number already in the table.
     *
     * @param  array<string, mixed>  $attributes
     */
    private static function takeNextNumber(Company $company, array $attributes, bool $catchUp): int
    {
        $split = static::numberSequence($attributes);
        $name = collect($split)->map(fn (mixed $value, string $column): string => "{$column}={$value}")
            ->prepend((new static)->getTable())
            ->implode(':');
        $counter = fn () => DB::table('number_sequences')->where('company_id', $company->id)->where('sequence', $name);

        $last = $counter()->lockForUpdate()->value('last_number');

        if ($last === null || $catchUp) {
            // "+ 0" compares the stored text as numbers, so 1000000 comes after 999999.
            $highest = (int) static::query()
                ->where('company_id', $company->id)
                ->where($split)
                ->max(DB::raw(static::NUMBER_COLUMN.' + 0'));

            DB::table('number_sequences')->insertOrIgnore(['company_id' => $company->id, 'sequence' => $name, 'last_number' => $highest]);
            $last = max((int) $counter()->lockForUpdate()->value('last_number'), $highest);
        }

        $next = (int) $last + 1;
        $counter()->update(['last_number' => $next]);

        return $next;
    }
}
