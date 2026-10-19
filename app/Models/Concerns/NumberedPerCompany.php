<?php

namespace App\Models\Concerns;

use App\Models\Company;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Records numbered per company in the order they are created ("000001", "000002", ...), like work
 * orders, invoices and official receipts. The model names the column with NUMBER_COLUMN.
 */
trait NumberedPerCompany
{
    /**
     * Creates the record with the next number in the company's sequence. Two records created at the
     * same moment may pick the same number; the unique index rejects the second, which then takes the next one.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function createNumbered(Company $company, array $attributes): static
    {
        $column = static::NUMBER_COLUMN;

        for ($attempt = 1; ; $attempt++) {
            $highest = static::query()
                ->where('company_id', $company->id)
                ->where(static::numberSequence($attributes))
                ->pluck($column)
                ->reduce(fn (int $max, string $number): int => max($max, (int) $number), 0);

            try {
                return static::query()->create([
                    ...$attributes,
                    'company_id' => $company->id,
                    $column => str_pad((string) ($highest + 1), 6, '0', STR_PAD_LEFT),
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= 3) {
                    throw $exception;
                }
            }
        }
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
}
