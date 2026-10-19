<?php

namespace App\Support\Billing;

use App\Enums\InvoiceLineKind;
use App\Models\Company;
use App\Models\Customer;
use App\Support\Format;
use App\Support\Money;

/**
 * The tax on a bill, as set in Company Setup → Operation Setup. Amounts are in cents.
 *
 * - The taxable amount is the sum of the taxable lines: water (when the company taxes it), charge
 *   elements marked Taxable (a taxable discount lowers it), work order fees and surcharges when the
 *   company taxes them. Memos and previous balances are never taxed.
 * - Exclusive (the default): the tax is added as its own line, e.g. 12% of 323.40 = 38.81.
 * - Inclusive: prices already contain the tax, so nothing is added; the tax inside them
 *   (323.40 × 12 / 112 = 34.65) is only shown on the bill.
 * - Customers of a tax-exempt account type (e.g. Government) are not taxed; the amount shows as exempt.
 *
 * @phpstan-import-type Line from BillCalculator
 *
 * @phpstan-type TaxSummary array{name: ?string, rate: ?string, inclusive: bool, taxableCents: int, taxCents: int, exemptCents: int}
 */
final class Tax
{
    /**
     * The lines with the tax line added (when the tax is exclusive), and the tax breakdown.
     *
     * @param  list<Line>  $lines
     * @return array{lines: list<Line>, summary: TaxSummary}
     */
    public static function apply(Company $company, Customer $customer, array $lines): array
    {
        $none = ['name' => null, 'rate' => null, 'inclusive' => false, 'taxableCents' => 0, 'taxCents' => 0, 'exemptCents' => 0];
        if (! $company->tax_enabled || (float) $company->tax_rate <= 0) {
            return ['lines' => $lines, 'summary' => $none];
        }

        $base = 0;
        foreach ($lines as $line) {
            if (self::isTaxable($company, $line)) {
                $base += $line['cents'];
            }
        }
        $base = max($base, 0);

        $summary = [...$none, 'name' => $company->tax_name ?: 'Tax', 'rate' => (string) $company->tax_rate, 'inclusive' => (bool) $company->tax_inclusive];

        if ($customer->accountType?->is_tax_exempt) {
            return ['lines' => $lines, 'summary' => [...$summary, 'exemptCents' => $base]];
        }
        if ($base === 0) {
            return ['lines' => $lines, 'summary' => $summary];
        }

        if ($company->tax_inclusive) {
            $tax = (int) round($base * (float) $company->tax_rate / (100 + (float) $company->tax_rate));

            return ['lines' => $lines, 'summary' => [...$summary, 'taxableCents' => $base - $tax, 'taxCents' => $tax]];
        }

        $tax = Money::percentOf($base, $company->tax_rate);
        $lines[] = [
            'kind' => InvoiceLineKind::Tax,
            'description' => "{$summary['name']} (".Format::amount($company->tax_rate).'%)',
            'quantity' => null,
            'rate' => null,
            'cents' => $tax,
        ];

        return ['lines' => $lines, 'summary' => [...$summary, 'taxableCents' => $base, 'taxCents' => $tax]];
    }

    /**
     * @param  Line  $line
     */
    private static function isTaxable(Company $company, array $line): bool
    {
        return match ($line['kind']) {
            InvoiceLineKind::Water => (bool) $company->tax_on_water,
            InvoiceLineKind::Charge => (bool) ($line['taxable'] ?? false),
            InvoiceLineKind::WorkOrderFee => (bool) $company->tax_on_fees,
            InvoiceLineKind::Surcharge => (bool) $company->tax_on_surcharges,
            default => false,
        };
    }

    /**
     * Invoice attributes that record the tax breakdown.
     *
     * @param  TaxSummary  $summary
     * @return array<string, mixed>
     */
    public static function invoiceAttributes(array $summary): array
    {
        return [
            'tax_name' => $summary['name'],
            'tax_rate' => $summary['rate'],
            'tax_inclusive' => $summary['inclusive'],
            'taxable_amount' => Money::fromCents($summary['taxableCents']),
            'tax_amount' => Money::fromCents($summary['taxCents']),
            'tax_exempt_amount' => Money::fromCents($summary['exemptCents']),
        ];
    }
}
