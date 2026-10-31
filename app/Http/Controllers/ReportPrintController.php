<?php

namespace App\Http\Controllers;

use App\Enums\Page;
use App\Support\Reports\Reports;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * A report as a printable page, with the filters from the query string.
 */
class ReportPrintController extends Controller
{
    public function __invoke(Request $request, string $report): View
    {
        $company = $request->user()->company;
        abort_if($company === null, 403);

        return view('print.report', [
            'report' => Reports::make(Page::from($report), $company, $request->query()),
            'company' => $company,
        ]);
    }
}
