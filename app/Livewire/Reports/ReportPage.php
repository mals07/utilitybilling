<?php

namespace App\Livewire\Reports;

use App\Enums\Page;
use App\Enums\ReportFilter;
use App\Livewire\Concerns\GuardsPageAccess;
use App\Support\Reports\Report;
use App\Support\Reports\Reports;
use App\Support\Reports\ReportTable;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A report page: the report's filters, its headline figures and tables, and links to print it or
 * export it as CSV. Every report page uses this component; the route says which report it shows.
 */
#[Layout('layouts.app')]
class ReportPage extends Component
{
    use GuardsPageAccess, WithPagination;

    /**
     * The choices of rows per page for each table.
     */
    public const ROWS_PER_PAGE = ['10', '20', '50', 'all'];

    public const DEFAULT_ROWS_PER_PAGE = '20';

    /**
     * The filter properties, in display order.
     */
    private const FILTERS = ['from', 'to', 'fromPeriod', 'period', 'zone', 'accountType', 'cashier'];

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

    /**
     * Rows per page of each table, keyed by the table's page name: "10", "20", "50" or "all".
     *
     * @var array<string, string>
     */
    public array $rowsPerPage = [];

    /**
     * How each sorted table is sorted, keyed by the table's page name. Tables not listed keep the
     * report's own order.
     *
     * @var array<string, array{column: int, direction: string}>
     */
    public array $sorts = [];

    public function mount(string $report): void
    {
        $this->reportPage = $report;
    }

    public function updated(string $property): void
    {
        // New filters mean new rows, so every table starts again from its first page.
        if (in_array($property, self::FILTERS, true)) {
            $this->resetTablePages();
        }
    }

    /**
     * Shows the table $pageName with $rows rows per page ("all" for one page), from its first page.
     */
    public function setRowsPerPage(string $pageName, string $rows): void
    {
        if (preg_match('/^[a-z0-9_]{1,60}$/', $pageName) !== 1) {
            return;
        }

        $this->rowsPerPage[$pageName] = in_array($rows, self::ROWS_PER_PAGE, true) ? $rows : self::DEFAULT_ROWS_PER_PAGE;
        $this->resetPage($pageName);
    }

    /**
     * Sorts the table $pageName by a column: ascending on the first click, descending on the second,
     * and back to the report's own order on the third. Starts again from the first page.
     */
    public function sortBy(string $pageName, int $column): void
    {
        if (preg_match('/^[a-z0-9_]{1,60}$/', $pageName) !== 1 || $column < 0) {
            return;
        }

        $current = $this->sorts[$pageName] ?? null;
        if ($current === null || $current['column'] !== $column) {
            $this->sorts[$pageName] = ['column' => $column, 'direction' => 'asc'];
        } elseif ($current['direction'] === 'asc') {
            $this->sorts[$pageName] = ['column' => $column, 'direction' => 'desc'];
        } else {
            unset($this->sorts[$pageName]);
        }
        $this->resetPage($pageName);
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
        $this->reset(self::FILTERS);
        $this->resetTablePages();
    }

    private function resetTablePages(): void
    {
        foreach (array_keys($this->paginators) as $pageName) {
            $this->resetPage($pageName);
        }
    }

    /**
     * The table's rows for its current page, in the chosen order, with the page links. Printouts and
     * CSV files keep every row in the report's own order.
     *
     * @return array{paginator: LengthAwarePaginator<int, list<mixed>>, rowsPerPage: string, sort: array{column: int, direction: string}|null}
     */
    private function paginate(ReportTable $table): array
    {
        $pageName = Str::slug($table->title, '_');
        $rowsPerPage = $this->rowsPerPage[$pageName] ?? self::DEFAULT_ROWS_PER_PAGE;
        $sort = $this->sorts[$pageName] ?? null;
        $column = $sort === null ? null : ($table->columns[$sort['column']] ?? null);

        $rows = $table->rows;
        if ($column !== null) {
            $index = $sort['column'];
            $direction = $sort['direction'] === 'desc' ? -1 : 1;
            // Empty values stay last whichever way the column is sorted.
            usort($rows, function (array $a, array $b) use ($column, $index, $direction): int {
                $aEmpty = ($a[$index] ?? null) === null || $a[$index] === '';
                $bEmpty = ($b[$index] ?? null) === null || $b[$index] === '';

                return $aEmpty || $bEmpty ? $aEmpty <=> $bEmpty : $direction * $column->type->compare($a[$index], $b[$index]);
            });
        }

        $total = count($rows);
        $perPage = $rowsPerPage === 'all' ? max($total, 1) : (int) $rowsPerPage;
        $page = min(max((int) $this->getPage($pageName), 1), max((int) ceil($total / $perPage), 1));

        return [
            'paginator' => new LengthAwarePaginator(
                array_slice($rows, ($page - 1) * $perPage, $perPage),
                $total,
                $perPage,
                $page,
                ['pageName' => $pageName],
            ),
            'rowsPerPage' => $rowsPerPage,
            'sort' => $column === null ? null : $sort,
        ];
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
            'tables' => array_map(fn (ReportTable $table): array => ['table' => $table, ...$this->paginate($table)], $report->tables()),
            'filters' => $report->appliedFilters(),
            'uses' => $uses,
            'today' => $company->today(),
            'currentPeriod' => substr($company->today(), 0, 7),
            'zones' => in_array(ReportFilter::Zone, $uses, true) ? Report::zoneOptions($company) : [],
            'accountTypes' => in_array(ReportFilter::AccountType, $uses, true) ? Report::accountTypeOptions($company) : [],
            'cashiers' => in_array(ReportFilter::Cashier, $uses, true) ? Report::cashierOptions($company) : [],
            'filtered' => array_filter($this->only(self::FILTERS)) !== [],
        ])->title($report->title());
    }
}
