<?php

namespace App\Http\Controllers;

use App\Enums\AdjustmentType;
use App\Enums\InvoiceLineKind;
use App\Enums\InvoiceStatus;
use App\Enums\Page;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\Payment;
use App\Support\Billing\Ledger;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * A customer's statement of account: every bill and payment in date order with a running balance.
 * Open to users who can see invoices or payments.
 */
class StatementController extends Controller
{
    public function __invoke(Request $request, int $customer): View
    {
        $user = $request->user();
        abort_unless($user->canAccessPage(Page::Invoices) || $user->canAccessPage(Page::Payments), 403);

        $customer = Customer::query()->where('company_id', $user->company_id)->with('company')->findOrFail($customer);

        $invoices = $customer->invoices()->where('status', '!=', InvoiceStatus::Void)->with(['lines', 'adjustments'])->get();

        // Each invoice at its amount before memos; active memos follow as their own entries.
        $bills = $invoices->map(fn (Invoice $invoice): array => [
            'date' => $invoice->invoice_date->toDateString(),
            'order' => 0,
            'reference' => "Invoice {$invoice->invoice_number}",
            'description' => $invoice->periodLabel() ? "Bill for {$invoice->periodLabel()}" : $invoice->kind->label(),
            'charge' => Money::toCents($invoice->total_amount)
                - Money::toCents($invoice->lines->where('kind', InvoiceLineKind::Adjustment)->sum('amount')),
            'payment' => 0,
            'due' => $invoice->due_date->format('m/d/Y'),
        ]);

        $memos = $invoices->flatMap(fn (Invoice $invoice) => $invoice->adjustments->reject(fn (InvoiceAdjustment $memo): bool => $memo->isVoid())
            ->map(fn (InvoiceAdjustment $memo): array => [
                'date' => $memo->issued_on->toDateString(),
                'order' => 1,
                'reference' => $memo->label(),
                'description' => "{$memo->type->label()} on invoice {$invoice->invoice_number} — {$memo->reason_category->label()}",
                'charge' => $memo->type === AdjustmentType::Debit ? Money::toCents($memo->amount) : 0,
                'payment' => $memo->type === AdjustmentType::Credit ? Money::toCents($memo->amount) : 0,
                'due' => null,
            ]));

        $payments = $customer->payments()->whereNull('voided_at')->get()
            ->map(fn (Payment $payment): array => [
                'date' => $payment->paid_on->toDateString(),
                'order' => 2,
                'reference' => "OR {$payment->receipt_number}",
                'description' => 'Payment — '.$payment->method->label(),
                'charge' => 0,
                'payment' => Money::toCents($payment->amount),
                'due' => null,
            ]);

        $balance = 0;
        $entries = $bills->concat($memos)->concat($payments)
            ->sortBy([['date', 'asc'], ['order', 'asc']])
            ->values()
            ->map(function (array $entry) use (&$balance): array {
                $balance += $entry['charge'] - $entry['payment'];

                return [...$entry, 'balance' => $balance];
            });

        return view('print.statement', [
            'customer' => $customer,
            'company' => $customer->company,
            'entries' => $entries,
            'summary' => Ledger::summary($customer),
        ]);
    }
}
