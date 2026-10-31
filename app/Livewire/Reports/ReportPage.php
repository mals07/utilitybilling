<?php

namespace App\Livewire\Reports;

use App\Enums\Page;
use App\Enums\ReportFilter;
use App\Livewire\Concerns\GuardsPageAccess;
use App\Support\Reports\Report;
use App\Support\Reports\Reports;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A report page: the report's filters, its headline figures and tables, and links to print it or
 * export it as CSV. Every report page uses this component; the route says which report it shows.
 */
#[Layout('layouts.app')]
class ReportPage extends Component
{
    use GuardsPageAccess;

    /**
     * The report's Page value, e.g. "reports.collections".
     */
    #[Locked]
    public string $reportPage = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(as: 'from_period', except: '')]
    public string $fromPeriod = '';

    #[Url(except: '')]
    public string $period = '';

    #[Url(except: '')]
    public string $zone = '';

    #[Url(as: 'account_type', except: '')]
    public string $accountType = '';

    #[Url(except: '')]
    public string $cashier = '';

    public function mount(string $report): void
    {
        $this->reportPage = $report;
    }

    protected function page(): Page
    {
        $page = Page::tryFrom($this->reportPage);
        abort_unless($page?->isReport() ?? false, 404);

        return $page;
    }

    /**
     * Back to the report's default filters.
     */
    public function resetFilters(): void
    {
        $this->reset(['from', 'to', 'fromPeriod', 'period', 'zone', 'accountType', 'cashier']);
    }

    public function render(): View
    {
        // Livewire updates skip the route middleware, so access is checked here too.
        abort_unless($this->user()->canAccessPage($this->page()), 403);

        $company = $this->company();
        $report = Reports::make($this->page(), $company, [
            'from' => $this->from,
            'to' => $this->to,
            'from_period' => $this->fromPeriod,
            'period' => $this->period,
            'zone' => $this->zone,
            'account_type' => $this->accountType,
            'cashier' => $this->cashier,
        ]);
        $uses = $report::filters();

        return view('livewire.reports.report-page', [
            'report' => $report,
            'filters' => $report->appliedFilters(),
            'uses' => $uses,
            'today' => $company->today(),
            'currentPeriod' => substr($company->today(), 0, 7),
            'zones' => in_array(ReportFilter::Zone, $uses, true) ? Report::zoneOptions($company) : [],
            'accountTypes' => in_array(ReportFilter::AccountType, $uses, true) ? Report::accountTypeOptions($company) : [],
            'cashiers' => in_array(ReportFilter::Cashier, $uses, true) ? Report::cashierOptions($company) : [],
            'filtered' => array_filter([$this->from, $this->to, $this->fromPeriod, $this->period, $this->zone, $this->accountType, $this->cashier]) !== [],
        ])->title($report->title());
    }
}
