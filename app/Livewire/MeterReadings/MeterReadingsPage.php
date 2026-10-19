<?php

namespace App\Livewire\MeterReadings;

use App\Enums\Access;
use App\Enums\CustomerStatus;
use App\Enums\MeterReadingStatus;
use App\Enums\Page;
use App\Enums\ReadingCode;
use App\Livewire\Concerns\GuardsPageAccess;
use App\Livewire\Concerns\ParsesIds;
use App\Models\Customer;
use App\Models\MeterReading;
use App\Support\Billing\BillCalculator;
use App\Support\Billing\BillingException;
use App\Support\Billing\BillingRun;
use App\Support\Billing\MeterHistory;
use App\Support\Billing\MeterReadings;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Meter Readings: readers work through a month's meters (one cluster/route at a time), typing each
 * present reading or marking the meter No access / Defective. Each reading is saved as it is entered.
 * Readings that look wrong wait in Needs review until someone confirms them or reads the meter again;
 * the rest are Ready for Generate Bills.
 */
#[Layout('layouts.app')]
#[Title('Meter Readings')]
class MeterReadingsPage extends Component
{
    use GuardsPageAccess, ParsesIds, WithPagination;

    public const PER_PAGE = 30;

    /**
     * Filter chips: value => label.
     */
    public const FILTERS = ['all' => 'All', 'unread' => 'Unread', 'review' => 'Needs review', 'ready' => 'Ready', 'billed' => 'Billed'];

    #[Url(as: 'period', except: '')]
    public string $period = '';

    #[Url(as: 'cluster', except: '')]
    public string $clusterId = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'show', except: 'all')]
    public string $filter = 'all';

    /**
     * The customer open in the side panel.
     */
    #[Url(as: 'c', except: '')]
    public string $selected = '';

    public string $readOn = '';

    /**
     * Readings typed but not saved (they failed), keyed by customer id.
     *
     * @var array<int|string, string>
     */
    public array $entries = [];

    /**
     * Why a typed reading couldn't be saved, keyed by customer id.
     *
     * @var array<int|string, string>
     */
    public array $rowErrors = [];

    public string $notes = '';

    /**
     * The present reading being typed in the side panel. It is saved only with the Save buttons,
     * after the panel shows the usage it makes.
     */
    public string $presentInput = '';

    protected function page(): Page
    {
        return Page::MeterReadings;
    }

    public function mount(): void
    {
        $today = BillingRun::today($this->company());
        if (BillingRun::periodError($this->period, $today) !== null) {
            $this->period = substr($today, 0, 7);
        }
        if (! array_key_exists($this->filter, self::FILTERS)) {
            $this->filter = 'all';
        }
        $this->readOn = $today;
        $this->loadNotes();
    }

    public function updatedPeriod(): void
    {
        $this->reset('entries', 'rowErrors', 'selected', 'notes');
        $this->resetPage();
    }

    public function updatedClusterId(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        if (! array_key_exists($this->filter, self::FILTERS)) {
            $this->filter = 'all';
        }
        $this->resetPage();
    }

    public function select(int $customerId): void
    {
        $this->selected = (string) $customerId;
        $this->loadNotes();

        // Starts from what was typed before (or the final reading of a disconnected meter); a saved
        // reading is retyped from blank so the reader looks at the meter again.
        $this->presentInput = (string) ($this->entries[$customerId] ?? '');
        $this->resetValidation('presentInput');
    }

    public function closePanel(): void
    {
        $this->reset('selected', 'notes', 'presentInput');
    }

    /**
     * Saves the reading typed in the side panel; with $next, opens the next meter in the list.
     */
    public function saveSelected(bool $next = false): void
    {
        $id = self::idFrom($this->selected);
        if ($id === null) {
            return;
        }

        $this->presentInput = trim($this->presentInput);
        if ($this->presentInput === '') {
            $this->addError('presentInput', 'Enter the present reading.');

            return;
        }

        if (! $this->save($id, $this->presentInput)) {
            return;
        }

        $this->flash('Reading saved.');
        $nextId = $next ? $this->nextCustomerId($id) : null;
        if ($nextId !== null) {
            $this->select($nextId);
        } else {
            $this->presentInput = '';
        }
    }

    /**
     * The meter after this one in the list (same month, cluster, search and filter), by account number.
     */
    private function nextCustomerId(int $customerId): ?int
    {
        $current = $this->customer($customerId);
        if ($current === null) {
            return null;
        }

        return $this->filtered(MeterReadings::customers($this->company(), $this->period, self::idFrom($this->clusterId), trim($this->search)))
            ->where('account_number', '>', $current->account_number)
            ->orderBy('account_number')
            ->value('customers.id');
    }

    private function loadNotes(): void
    {
        $id = self::idFrom($this->selected);
        $this->notes = $id === null ? '' : (string) $this->reading($id)?->notes;
    }

    /**
     * The customer, if they belong to the company and have a meter.
     */
    private function customer(int $id): ?Customer
    {
        return $this->company()->customers()->whereNotNull('meter_number')->find($id);
    }

    private function reading(int $customerId): ?MeterReading
    {
        return $this->company()->meterReadings()->where('customer_id', $customerId)->where('billing_period', $this->period)->first();
    }

    /**
     * Why readings can't be saved right now (the month or reading date), if anything.
     */
    private function settingsError(): ?string
    {
        $today = BillingRun::today($this->company());
        if ($error = BillingRun::periodError($this->period, $today)) {
            return $error;
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->readOn) || ! strtotime($this->readOn)) {
            return 'Enter the reading date.';
        }

        return $this->readOn > $today ? "The reading date can't be in the future." : null;
    }

    /**
     * Saves a present reading (or an estimate code), replacing an unbilled one. Returns whether it
     * was saved; if not, the reason is in $rowErrors.
     */
    public function save(int $customerId, string $value, string $code = 'normal'): bool
    {
        $value = trim($value);
        $code = ReadingCode::tryFrom($code) ?? ReadingCode::Normal;
        $existing = $this->reading($customerId);

        if ($value === '' && ! $code->isEstimated()) {
            unset($this->entries[$customerId], $this->rowErrors[$customerId]);

            return false;
        }
        if ($existing !== null && ! $code->isEstimated() && $existing->reading_code === $code && $existing->present_reading !== null
            && preg_match(BillingRun::READING_PATTERN, $value) && (float) $existing->present_reading === (float) $value) {
            return true;
        }

        if (! $this->allowed($existing === null ? Access::Add : Access::Edit)) {
            return false;
        }

        $customer = $this->customer($customerId);
        if ($customer === null) {
            $this->flash('That customer no longer exists. The list has been refreshed.', 'error');

            return false;
        }

        if ($error = $this->settingsError()) {
            $this->rowErrors[$customerId] = $error;
            $this->entries[$customerId] = $value;

            return false;
        }

        try {
            MeterReadings::save($customer, $this->period, $value, $code, $this->readOn, $this->user());
        } catch (BillingException $exception) {
            $this->rowErrors[$customerId] = $exception->getMessage();
            $this->entries[$customerId] = $value;

            return false;
        }

        unset($this->entries[$customerId], $this->rowErrors[$customerId]);

        return true;
    }

    /**
     * Marks the meter No access or Defective: usage is estimated.
     */
    public function markEstimated(int $customerId, string $code): void
    {
        $code = ReadingCode::tryFrom($code);
        if ($code === null || ! $code->isEstimated()) {
            return;
        }

        $this->save($customerId, '', $code->value);
    }

    /**
     * Saves the typed reading (or the saved one) as a meter that rolled over past its highest reading.
     */
    public function saveRollover(int $customerId): void
    {
        $typed = (string) self::idFrom($this->selected) === (string) $customerId ? trim($this->presentInput) : '';
        $value = $typed !== '' ? $typed : ($this->entries[$customerId] ?? (string) ($this->reading($customerId)?->present_reading ?? ''));

        $this->save($customerId, $value, ReadingCode::Rollover->value);
    }

    /**
     * Accepts a reading that needs review, so it can be billed.
     */
    public function confirm(int $customerId): void
    {
        if (! $this->allowed(Access::Edit)) {
            return;
        }

        $reading = $this->reading($customerId);
        if ($reading === null) {
            $this->flash('That reading no longer exists. The page has been refreshed.', 'error');

            return;
        }

        try {
            MeterReadings::confirm($reading, $this->user());
        } catch (BillingException $exception) {
            $this->flash($exception->getMessage(), 'error');

            return;
        }

        $this->flash('Reading confirmed. It is ready to bill.');
    }

    /**
     * Deletes the saved reading so the meter can be read again.
     */
    public function reread(int $customerId): void
    {
        if (! $this->allowed(Access::Edit)) {
            return;
        }

        $reading = $this->reading($customerId);
        if ($reading === null) {
            return;
        }

        try {
            MeterReadings::remove($reading);
        } catch (BillingException $exception) {
            $this->flash($exception->getMessage(), 'error');

            return;
        }

        unset($this->entries[$customerId], $this->rowErrors[$customerId]);
        $this->notes = '';
        $this->flash('Reading cleared. Enter the new reading when the meter is read again.');
    }

    public function saveNotes(): void
    {
        $id = self::idFrom($this->selected);
        if ($id === null || ! $this->allowed(Access::Edit)) {
            return;
        }

        $this->notes = trim($this->notes);
        $this->validate(['notes' => ['max:500']], ['notes.max' => 'The notes must not be greater than 500 characters.']);

        $reading = $this->reading($id);
        if ($reading === null) {
            $this->addError('notes', 'Save a reading first, then add notes to it.');

            return;
        }

        $reading->update(['notes' => $this->notes === '' ? null : $this->notes]);
        $this->flash('Notes saved.');
    }

    /**
     * The customers of the month narrowed by the filter chip.
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    private function filtered(Builder $query): Builder
    {
        $period = $this->period;

        return match ($this->filter) {
            'unread' => $query->whereDoesntHave('meterReadings', fn (Builder $readings) => $readings->where('billing_period', $period)),
            'review', 'ready', 'billed' => $query->whereHas('meterReadings', fn (Builder $readings) => $readings
                ->where('billing_period', $period)
                ->where('status', MeterReadingStatus::from($this->filter))),
            default => $query,
        };
    }

    public function render(): View
    {
        $company = $this->company();
        $today = BillingRun::today($company);
        $periodValid = BillingRun::periodError($this->period, $today) === null;
        $clusterId = self::idFrom($this->clusterId);

        $base = MeterReadings::customers($company, $this->period, $clusterId, '');
        $readings = $company->meterReadings()
            ->where('billing_period', $this->period)
            ->whereIn('customer_id', (clone $base)->select('customers.id'));

        $statusCounts = (clone $readings)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all();
        $total = (clone $base)->count();
        $read = array_sum($statusCounts);
        $stats = [
            'total' => $total,
            'read' => $read,
            'unread' => max($total - $read, 0),
            'review' => (int) ($statusCounts[MeterReadingStatus::Review->value] ?? 0),
            'ready' => (int) ($statusCounts[MeterReadingStatus::Ready->value] ?? 0),
            'billed' => (int) ($statusCounts[MeterReadingStatus::Billed->value] ?? 0),
            'usage' => (float) (clone $readings)->sum('consumption'),
            'percent' => $total === 0 ? 0 : (int) floor($read / $total * 100),
        ];

        $period = $this->period;
        $customers = $this->filtered(MeterReadings::customers($company, $period, $clusterId, trim($this->search)))
            ->with([
                'meterReadings' => fn ($query) => $query->where('billing_period', $period),
                'meterType:id,name,dial_digits',
            ])
            ->orderBy('account_number')
            ->paginate(self::PER_PAGE);

        // What each row shows besides the customer: the saved reading, or the reading to start from.
        $rows = [];
        foreach ($customers as $customer) {
            /** @var Customer $customer */
            $reading = $customer->meterReadings->first();
            $history = $reading === null && $periodValid ? MeterHistory::for($customer, $period) : null;
            $disconnected = $customer->status === CustomerStatus::Disconnected;

            // A disconnected customer is billed up to the final reading taken at disconnection.
            if ($history !== null && $disconnected && $history['finalReading'] !== null && ! isset($this->entries[$customer->id])) {
                $this->entries[$customer->id] = BillCalculator::quantity($history['finalReading']);
            }

            $rows[$customer->id] = [
                'reading' => $reading,
                'previous' => $reading !== null ? (float) $reading->previous_reading : $history['previous'] ?? null,
                'finalReading' => $disconnected && $history !== null ? $history['finalReading'] : null,
            ];
        }

        return view('livewire.meter-readings.meter-readings-page', [
            'customers' => $customers,
            'rows' => $rows,
            'stats' => $stats,
            'panel' => $this->panel($periodValid, $today),
            'clusters' => $company->clusterTypes()->orderBy('name')->pluck('name', 'id')->all(),
            'periodLabel' => $periodValid ? BillingRun::periodLabel($this->period) : null,
            'today' => $today,
            'access' => $this->access,
        ]);
    }

    /**
     * What the side panel shows for the selected customer: their reading, recent usage and the bill it makes.
     *
     * @return array{customer: Customer, reading: ?MeterReading, history: list<array{label: string, usage: float, estimated: bool}>, average: ?float, maxUsage: float, previous: ?float, lines: list<array{description: string, amount: string}>, total: ?string, error: ?string, billedInvoice: ?string, preview: array{usage: ?float, deducted: float, flags: list<string>, error: ?string}|null}|null
     */
    private function panel(bool $periodValid, string $today): ?array
    {
        $id = self::idFrom($this->selected);
        $customer = $id === null ? null : $this->company()->customers()
            ->with(['rateType:id,name', 'clusterType:id,name', 'accountType:id,name,is_tax_exempt', 'meterType:id,name,dial_digits'])
            ->find($id);
        if ($customer === null) {
            return null;
        }

        $reading = $this->reading($customer->id)?->load(['createdBy:id,name', 'confirmedBy:id,name', 'invoice']);

        $history = MeterReading::query()
            ->where('customer_id', $customer->id)
            ->whereNotNull('invoice_id')
            ->where('billing_period', '<', $this->period)
            ->orderByDesc('billing_period')
            ->limit(6)
            ->get()
            ->reverse()
            ->map(fn (MeterReading $past): array => [
                'label' => BillingRun::periodLabel($past->billing_period),
                'usage' => $past->usage(),
                'estimated' => $past->present_reading === null,
            ])
            ->values()
            ->all();
        $recent = array_slice(array_column($history, 'usage'), -3);
        $average = $recent === [] ? null : round(array_sum($recent) / count($recent), 3);

        $lines = [];
        $total = null;
        $error = null;
        if ($reading !== null && $reading->isBilled()) {
            foreach ($reading->invoice->lines as $line) {
                $lines[] = ['description' => $line->description, 'amount' => (string) $line->amount];
            }
            $total = (string) $reading->invoice->total_amount;
        } elseif ($reading !== null) {
            try {
                $bill = BillingRun::bill($customer, $reading->usage(), $today);
                foreach ($bill['lines'] as $line) {
                    $lines[] = ['description' => $line['description'].($line['quantity'] !== null ? " · {$line['quantity']} cu.m." : ''), 'amount' => Money::fromCents($line['cents'])];
                }
                $total = Money::fromCents($bill['totalCents']);
            } catch (BillingException $exception) {
                $error = $exception->getMessage();
            }
        }

        $previous = null;
        if ($reading === null && $periodValid) {
            try {
                $previous = MeterHistory::for($customer, $this->period)['previous'];
            } catch (BillingException) {
                $previous = null;
            }
        }

        // What the reading being typed would make, shown before it is saved.
        $preview = null;
        $typed = trim($this->presentInput);
        if ($typed !== '' && $periodValid && ! ($reading?->isBilled() ?? false)) {
            try {
                $evaluated = MeterReadings::evaluate($customer, $this->period, $typed, ReadingCode::Normal);
                $preview = ['usage' => $evaluated['usage'], 'deducted' => $evaluated['deducted'], 'flags' => $evaluated['flags'], 'error' => null];
            } catch (BillingException $exception) {
                $preview = ['usage' => null, 'deducted' => 0.0, 'flags' => [], 'error' => $exception->getMessage()];
            }
        }

        return [
            'customer' => $customer,
            'reading' => $reading,
            'history' => $history,
            'average' => $average,
            'maxUsage' => max([1.0, $reading?->usage() ?? 0, ...array_column($history, 'usage')]),
            'previous' => $previous ?? ($reading === null ? null : (float) $reading->previous_reading),
            'lines' => $lines,
            'total' => $total,
            'error' => $error,
            'billedInvoice' => $reading?->invoice?->invoice_number,
            'preview' => $preview,
        ];
    }
}
