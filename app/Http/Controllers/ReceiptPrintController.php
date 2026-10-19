<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The printable official receipt of one of the company's payments.
 */
class ReceiptPrintController extends Controller
{
    public function __invoke(Request $request, int $payment): View
    {
        $payment = Payment::query()
            ->where('company_id', $request->user()->company_id)
            ->with(['customer', 'allocations.invoice', 'receivedBy:id,name', 'company'])
            ->findOrFail($payment);

        return view('print.receipt', ['payment' => $payment, 'company' => $payment->company]);
    }
}
