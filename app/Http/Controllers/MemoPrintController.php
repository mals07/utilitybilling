<?php

namespace App\Http\Controllers;

use App\Models\InvoiceAdjustment;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The printable credit or debit memo of one of the company's invoices.
 */
class MemoPrintController extends Controller
{
    public function __invoke(Request $request, int $memo): View
    {
        $memo = InvoiceAdjustment::query()
            ->where('company_id', $request->user()->company_id)
            ->with(['customer', 'invoice', 'workOrder:id,order_number,order_type', 'createdBy:id,name', 'company'])
            ->findOrFail($memo);

        return view('print.memo', ['memo' => $memo, 'company' => $memo->company]);
    }
}
