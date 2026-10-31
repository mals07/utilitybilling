<?php

namespace App\Http\Controllers;

use App\Enums\Page;
use App\Support\Reports\Reports;
use App\Support\Reports\ReportTable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A report as a CSV file for Excel: the title and filters, then each table with its heading row,
 * rows and totals. Values are plain numbers and ISO dates.
 */
class ReportExportController extends Controller
{
    public function __invoke(Request $request, string $report): StreamedResponse
    {
        $company = $request->user()->company;
        abort_if($company === null, 403);

        $report = Reports::make(Page::from($report), $company, $request->query());
        $fileName = Str::slug($report->title().' '.$report->subtitle()).'.csv';

        return response()->streamDownload(function () use ($report, $company): void {
            $out = fopen('php://output', 'w');
            // A byte order mark, so Excel reads names with accents as UTF-8.
            fwrite($out, "\u{FEFF}");

            fputcsv($out, [$company->company_name]);
            fputcsv($out, [$report->title()]);
            fputcsv($out, [$report->subtitle()]);
            if ($report->filterLabel() !== null) {
                fputcsv($out, [$report->filterLabel()]);
            }

            foreach ($report->tables() as $table) {
                fputcsv($out, []);
                fputcsv($out, [$table->title]);
                fputcsv($out, array_map(fn ($column): string => $column->label, $table->columns));
                foreach ($table->rows as $row) {
                    fputcsv($out, $this->cells($table, $row));
                }
                if ($table->totals !== null) {
                    fputcsv($out, $this->cells($table, $table->totals));
                }
            }

            fclose($out);
        }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  list<mixed>  $row
     * @return list<string>
     */
    private function cells(ReportTable $table, array $row): array
    {
        return array_map(fn ($column, $value): string => $column->type->csv($value), $table->columns, $row);
    }
}
