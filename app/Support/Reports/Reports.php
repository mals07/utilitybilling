<?php

namespace App\Support\Reports;

use App\Enums\Page;
use App\Models\Company;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The report behind each report page.
 */
final class Reports
{
    /**
     * @var array<string, class-string<Report>>
     */
    private const CLASSES = [
        Page::CollectionsReport->value => CollectionsReport::class,
        Page::AgingReport->value => AgingReport::class,
        Page::BillingReport->value => BillingReport::class,
        Page::AdjustmentsReport->value => AdjustmentsReport::class,
        Page::EfficiencyReport->value => EfficiencyReport::class,
        Page::ConsumptionReport->value => ConsumptionReport::class,
        Page::ReadingsReport->value => ReadingsReport::class,
        Page::DisconnectionsReport->value => DisconnectionsReport::class,
        Page::WorkOrdersReport->value => WorkOrdersReport::class,
    ];

    /**
     * @return class-string<Report>
     */
    public static function classFor(Page $page): string
    {
        return self::CLASSES[$page->value] ?? throw new InvalidArgumentException("{$page->value} is not a report.");
    }

    /**
     * The report for the page, with the raw filter values checked and defaults filled in.
     *
     * @param  array<string, mixed>  $input  Keyed like ReportFilters::query().
     */
    public static function make(Page $page, Company $company, array $input): Report
    {
        $class = self::classFor($page);

        return new $class($company, $class::filtersFrom($company, $input));
    }

    /**
     * "/reports/collections" for Page::CollectionsReport.
     */
    public static function path(Page $page): string
    {
        return '/reports/'.Str::after($page->value, 'reports.');
    }
}
