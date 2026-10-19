<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Support\Billing\BillingRun;
use App\Support\Billing\Ledger;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The printable bill of one of the company's invoices.
 */
class InvoicePrintController extends Controller
{
    public function __invoke(Request $request, int $invoice): View
    {
        $invoice = Invoice::query()
            ->where('company_id', $request->user()->company_id)
            ->with(['customer', 'lines', 'meterReading', 'workOrder:id,order_number', 'allocations.payment', 'company'])
            ->findOrFail($invoice);

        $customer = $invoice->customer;
        $summary = Ledger::summary($customer);

        // What else the customer owes besides this bill, now.
        $otherOwed = $summary['owed'] - ($invoice->status->isOpen() ? Money::toCents($invoice->balance) : 0);

        return view('print.invoice', [
            'invoice' => $invoice,
            'company' => $invoice->company,
            'customer' => $customer,
            'today' => BillingRun::today($invoice->company),
            'otherOwedCents' => max($otherOwed, 0),
            'creditCents' => $summary['credit'],
            'isVoid' => $invoice->status === InvoiceStatus::Void,
        ]);
    }
}
