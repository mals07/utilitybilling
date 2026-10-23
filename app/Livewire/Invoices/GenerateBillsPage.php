<?php

namespace App\Livewire\Invoices;

use App\Enums\Access;
use App\Enums\MeterReadingStatus;
use App\Enums\Page;
use App\Livewire\Concerns\GuardsPageAccess;
use App\Livewire\Concerns\ParsesIds;
use App\Models\Customer;
use App\Models\MeterReading;
use App\Support\Billing\BillingException;
use App\Support\Billing\BillingRun;
use App\Support\Billing\MeterReadings;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Generate Bills: the monthly billing run. Lists the month's readings that are Ready to bill (entered
 * and checked on the Meter Readings page) with the bill each makes, and issues them all at once.
 */
#[Layout('layouts.app')]
#[Title('Generate Bills')]
class GenerateBillsPage extends Component
{
    use GuardsPageAccess, ParsesIds, WithPagination;

    public const PER_PAGE = 25;

    /**
     * Readings billed per request during a billing run.
     */
    public const BATCH_SIZE = 25;

    #[Url(as: 'period', except: '')]
    public string $period = '';

    public string $invoiceDate = '';

    #[Url(as: 'cluster', except: '')]
    public string $clusterId = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /**
     * Why a reading couldn't be billed in the last run, keyed by reading id.
     *
     * @var array<int|string, string>
     */
    public array $failures = [];

    /**
     * The billing run in progress: what it bills, the last reading id done, and bills issued so far.
     *
     * @var array{period: string, invoiceDate: string, clusterId: string, afterId: int, issued: int, total: int}|null
     */
    #[Locked]
    public ?array $run = null;

    protected function page(): Page
    {
        return Page::Invoices;
    }

    public function mount(): void
    {
        $today = BillingRun::today($this->company());
        if (BillingRun::periodError($this->period, $today) !== null) {
            $this->period = substr($today, 0, 7);
        }
        $this->invoiceDate = $today;
    }

    public function updatedPeriod(): void
    {
        $this->reset('failures');
        $this->resetPage();
        $this->resetValidation('period');
    }

    public function updatedClusterId(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedInvoiceDate(): void
    {
        $this->resetValidation('invoiceDate');
    }

    /**
     * The month's readings of the chosen cluster (all of them, whatever their status).
     *
     * @return Builder<MeterReading>
     */
    private function readings(?string $period = null, ?string $cluster = null): Builder
    {
        $clusterId = self::idFrom($cluster ?? $this->clusterId);

        return MeterReading::query()
            ->where('company_id', $this->company()->id)
            ->where('billing_period', $period ?? $this->period)
            ->when($clusterId !== null, fn (Builder $query) => $query->whereHas('customer', fn (Builder $customer) => $customer->where('cluster_type_id', $clusterId)));
    }

    /**
     * @return array<string, string>
     */
    private function settingsErrors(): array
    {
        $today = BillingRun::today($this->company());
        $errors = [];

        if ($error = BillingRun::periodError($this->period, $today)) {
            $errors['period'] = $error;
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->invoiceDate) || ! strtotime($this->invoiceDate)) {
            $errors['invoiceDate'] = 'Enter the invoice date.';
        } elseif ($this->invoiceDate > $today) {
            $errors['invoiceDate'] = "The invoice date can't be in the future.";
        }

        return $errors;
    }

    /**
     * Starts a billing run: a bill for every reading of the month (and cluster) that is Ready to bill.
     * Readings that can't be billed stay Ready and show why.
     */
    public function generate(): void
    {
        if (! $this->allowed(Access::Add) || $this->run !== null) {
            return;
        }

        $this->resetValidation();
        if ($errors = $this->settingsErrors()) {
            foreach ($errors as $key => $message) {
                $this->addError($key, $message);
            }

            return;
        }

        $this->failures = [];
        $this->run = [
            'period' => $this->period,
            'invoiceDate' => $this->invoiceDate,
            'clusterId' => $this->clusterId,
            'afterId' => 0,
            'issued' => 0,
            'total' => $this->readyToBill($this->period, $this->clusterId)->count(),
        ];

        $this->generateBatch();
    }

    /**
     * Bills the run's next BATCH_SIZE readings, then asks the browser for the next batch, so a month of
     * thousands of customers isn't billed in one request that could time out. The run keeps the month,
     * invoice date and cluster it started with.
     */
    public function generateBatch(): void
    {
        if ($this->run === null || ! $this->allowed(Access::Add)) {
            return;
        }

        $run = $this->run;
        $readings = $this->readyToBill($run['period'], $run['clusterId'])
            ->where('id', '>', $run['afterId'])
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->get();

        foreach ($readings as $reading) {
            try {
                BillingRun::generateFor($reading, $run['invoiceDate'], $this->user());
                $run['issued']++;
            } catch (BillingException $exception) {
                $this->failures[$reading->id] = $exception->getMessage();
            }
            $run['afterId'] = $reading->id;
        }

        if ($readings->count() === self::BATCH_SIZE) {
            $this->run = $run;
            $this->js('$wire.generateBatch()');

            return;
        }

        $this->run = null;
        $this->finishRun($run['period'], $run['issued']);
    }

    private function finishRun(string $period, int $issued): void
    {
        $failed = count($this->failures);
        if ($issued === 0 && $failed === 0) {
            $this->flash('No readings are ready to bill. Enter and check them on the Meter Readings page first.', 'error');

            return;
        }

        $this->flash(trim(
            ($issued > 0 ? "{$issued} bill".($issued === 1 ? '' : 's').' generated for '.BillingRun::periodLabel($period).'. ' : '')
            .($failed > 0 ? "{$failed} could not be billed; see the messages in the table." : ''),
        ), $failed > 0 && $issued === 0 ? 'error' : 'success');
    }

    /**
     * @return Builder<MeterReading>
     */
    private function readyToBill(string $period, string $clusterId): Builder
    {
        return $this->readings($period, $clusterId)->where('status', MeterReadingStatus::Ready)->whereNull('invoice_id');
    }

    public function render(): View
    {
        $company = $this->company();
        $today = BillingRun::today($company);
        $periodValid = BillingRun::periodError($this->period, $today) === null;
        $search = trim($this->search);
        $clusterId = self::idFrom($this->clusterId);

        $statusCounts = $this->readings()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all();
        $toRead = $periodValid ? MeterReadings::customers($company, $this->period, $clusterId, '')
            ->whereDoesntHave('meterReadings', fn (Builder $query) => $query->where('billing_period', $this->period))
            ->count() : 0;

        $readings = $this->readings()
            ->whereIn('status', [MeterReadingStatus::Ready, MeterReadingStatus::Billed])
            ->when($search !== '', fn (Builder $query) => $query->whereIn('customer_id', Customer::idsMatching(
                $company->id, $search, ['account_number', 'first_name', 'last_name', 'meter_number'],
            )))
            ->with(['customer.rateType:id,name', 'invoice:id,invoice_number,total_amount'])
            ->orderBy(Customer::query()->select('account_number')->whereColumn('customers.id', 'meter_readings.customer_id'))
            ->paginate(self::PER_PAGE);

        // The bill each unbilled reading on this page makes, or why it can't be worked out.
        $previews = [];
        $date = isset($this->settingsErrors()['invoiceDate']) ? $today : $this->invoiceDate;
        foreach ($readings as $reading) {
            /** @var MeterReading $reading */
            if ($reading->isBilled()) {
                continue;
            }
            try {
                $bill = BillingRun::bill($reading->customer, $reading->usage(), $date);
                $notes = array_filter([
                    $bill['minimumApplied'] ? 'Minimum consumption' : null,
                    $bill['fees']->isNotEmpty() ? 'Work order fees' : null,
                    $bill['overdue']->isNotEmpty() ? 'Overdue surcharge' : null,
                    $bill['tax']['exemptCents'] > 0 ? 'Tax exempt' : null,
                ]);
                $previews[$reading->id] = ['total' => Money::fromCents($bill['totalCents']), 'note' => implode(' · ', $notes)];
            } catch (BillingException $exception) {
                $previews[$reading->id] = ['error' => $exception->getMessage()];
            }
        }

        return view('livewire.invoices.generate-bills-page', [
            'readings' => $readings,
            'previews' => $previews,
            'counts' => [
                'ready' => (int) ($statusCounts[MeterReadingStatus::Ready->value] ?? 0),
                'review' => (int) ($statusCounts[MeterReadingStatus::Review->value] ?? 0),
                'billed' => (int) ($statusCounts[MeterReadingStatus::Billed->value] ?? 0),
                'toRead' => $toRead,
            ],
            'clusters' => $company->clusterTypes()->orderBy('name')->pluck('name', 'id')->all(),
            'periodLabel' => $periodValid ? BillingRun::periodLabel($this->period) : null,
            'dueDays' => $company->due_date_days ?? 0,
            'today' => $today,
            'canRead' => $this->user()->canAccessPage(Page::MeterReadings),
            'access' => $this->access,
        ]);
    }
}
