<?php

namespace App\Support\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\OverdueHandling;
use App\Models\Company;
use App\Models\Invoice;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * For companies that add the surcharge to the overdue invoice itself: adds the customer's after-due
 * charge elements to each open invoice past its due date, once per invoice.
 */
final class Surcharges
{
    /**
     * @return int How many invoices were surcharged.
     */
    public static function applyToOverdueInvoices(Company $company, string $today): int
    {
        if ($company->overdue_handling !== OverdueHandling::SameInvoice) {
            return 0;
        }

        $ids = $company->invoices()
            ->whereIn('status', InvoiceStatus::openValues())
            ->whereDate('due_date', '<', $today)
            ->whereNull('surcharged_at')
            ->pluck('id');

        $count = 0;
        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id): int {
                $invoice = Invoice::query()->lockForUpdate()->find($id);
                if ($invoice === null || ! $invoice->status->isOpen() || $invoice->surcharged_at !== null) {
                    return 0;
                }

                $chargeType = $invoice->customer->chargeType?->load('elements.chargeElement');
                $lines = BillCalculator::surcharges($chargeType, Money::toCents($invoice->balance), '(overdue)', null);
                $sort = (int) $invoice->lines()->max('sort') + 1;

                foreach ($lines as $line) {
                    $invoice->lines()->create([
                        'kind' => $line['kind'],
                        'description' => $line['description'],
                        'amount' => Money::fromCents($line['cents']),
                        'charge_element_id' => $line['charge_element_id'] ?? null,
                        'sort' => $sort++,
                    ]);
                }

                $added = array_sum(array_column($lines, 'cents'));
                $invoice->update([
                    'total_amount' => Money::fromCents(max(Money::toCents($invoice->total_amount) + $added, 0)),
                    'surcharged_at' => now(),
                ]);
                Ledger::refresh($invoice);
                Ledger::settle($invoice->customer);

                return 1;
            });
        }

        return $count;
    }
}
