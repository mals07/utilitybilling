<?php

namespace App\Support\Billing;

use App\Enums\ChargeValueType;
use App\Enums\DistributionType;
use App\Enums\InvoiceLineKind;
use App\Models\ChargeType;
use App\Models\ChargeTypeElement;
use App\Models\RateType;
use App\Support\Format;
use App\Support\Money;

/**
 * Works out the lines of a bill. Amounts are in cents.
 *
 * - Water is tiered: each cubic meter is charged at the rate of the range it falls in, so 15 cu.m.
 *   on 0–10 @ 10.00 and 11–20 @ 25.00 is 10 × 10.00 + 5 × 25.00. Usage above the last range is
 *   charged at the last range's rate. Usage below the rate's minimum consumption is billed as the minimum.
 * - Charge elements of the customer's charge type: Credit adds to the bill, Debit deducts from it.
 *   A percentage is of the water charge; after-due elements (e.g. Surcharge) are of the overdue amount.
 *
 * @phpstan-type Line array{kind: InvoiceLineKind, description: string, quantity: ?string, rate: ?string, cents: int, charge_element_id?: ?int, work_order_id?: ?int, source_invoice_id?: ?int}
 */
final class BillCalculator
{
    /**
     * @return array{billed: float, minimumApplied: bool, lines: list<Line>}
     *
     * @throws BillingException When the rate has no consumption ranges.
     */
    public static function water(RateType $rate, float $consumption): array
    {
        $ranges = $rate->ranges;
        if ($ranges->isEmpty()) {
            throw new BillingException("The {$rate->name} rate has no consumption ranges. Add them on the Rates page.");
        }

        $minimum = (float) ($rate->setup?->minimum_consumption ?? 0);
        $billed = max($consumption, $minimum);
        $minimumApplied = $consumption < $minimum;

        $lines = [];
        $last = $ranges->last();
        foreach ($ranges as $range) {
            // Range 0–10 covers the first 10 cu.m.; 11–20 the next 10; the last range takes everything above.
            $lower = max($range->range_from - 1, 0);
            $upper = $range->is($last) ? INF : (float) $range->range_to;
            $units = max(min($billed, $upper) - $lower, 0);
            if ($units <= 0) {
                continue;
            }

            $lines[] = [
                'kind' => InvoiceLineKind::Water,
                'description' => $range->description ?: "{$range->range_from}–{$range->range_to} cu.m.",
                'quantity' => self::quantity($units),
                'rate' => (string) $range->rate,
                'cents' => Money::times($units, $range->rate),
            ];
        }

        return ['billed' => $billed, 'minimumApplied' => $minimumApplied, 'lines' => $lines];
    }

    /**
     * The charge type's regular (not after-due) elements, against the water charge.
     *
     * @return list<Line>
     */
    public static function charges(?ChargeType $chargeType, int $waterCents): array
    {
        return self::elementLines($chargeType, false, $waterCents, InvoiceLineKind::Charge, '');
    }

    /**
     * The charge type's after-due elements for one overdue amount, e.g. "Surcharge (2%) on invoice 000004".
     *
     * @return list<Line>
     */
    public static function surcharges(?ChargeType $chargeType, int $overdueCents, string $suffix, ?int $sourceInvoiceId): array
    {
        return array_map(
            fn (array $line): array => [...$line, 'source_invoice_id' => $sourceInvoiceId],
            self::elementLines($chargeType, true, $overdueCents, InvoiceLineKind::Surcharge, $suffix),
        );
    }

    /**
     * @return list<Line>
     */
    private static function elementLines(?ChargeType $chargeType, bool $afterDue, int $baseCents, InvoiceLineKind $kind, string $suffix): array
    {
        if ($chargeType === null) {
            return [];
        }

        $lines = [];
        foreach ($chargeType->elements as $row) {
            /** @var ChargeTypeElement $row */
            $element = $row->chargeElement;
            if ($element === null || (bool) $element->is_after_due !== $afterDue) {
                continue;
            }

            $percentage = $row->value_type === ChargeValueType::Percentage;
            $cents = $percentage ? Money::percentOf($baseCents, $row->value) : Money::toCents($row->value);
            if ($cents === 0) {
                continue;
            }
            if ($element->distribution_type === DistributionType::Debit) {
                $cents = -$cents;
            }

            $lines[] = [
                'kind' => $kind,
                'description' => trim($element->name.($percentage ? ' ('.Format::amount($row->value).'%)' : '').' '.$suffix),
                'quantity' => null,
                'rate' => null,
                'cents' => $cents,
                'charge_element_id' => $element->id,
            ];
        }

        return $lines;
    }

    /**
     * "15" or "15.5" — cubic meters without trailing zeros.
     */
    public static function quantity(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
