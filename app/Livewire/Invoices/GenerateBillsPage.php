<?php

namespace App\Livewire\Invoices;

use App\Enums\Access;
use App\Enums\CustomerStatus;
use App\Enums\Page;
use App\Livewire\Concerns\GuardsPageAccess;
use App\Livewire\Concerns\ParsesIds;
use App\Models\Customer;
use App\Support\Billing\BillCalculator;
use App\Support\Billing\BillingException;
use App\Support\Billing\BillingRun;
use App\Support\Billing\MeterHistory;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Generate Bills: the monthly billing run. Lists the customers to bill for a month with their previous
 * reading; staff enter each present reading (or mark the meter defective), see the bill worked out as
 * they go, then issue all the bills at once.
 */
#[Layout('layouts.app')]
#[Title('Generate Bills')]
class GenerateBillsPage extends Component
{
    use GuardsPageAccess, ParsesIds, WithPagination;

    public const PER_PAGE = 25;

    #[Url(as: 'period', except: '')]
    public string $period = '';

    public string $invoiceDate = '';

    public string $readOn = '';

    #[Url(as: 'cluster', except: '')]
    public string $clusterId = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /**
     * Readings entered, keyed by customer id.
     *
     * @var array<int|string, array{present: string, defective: bool}>
     */
    public array $entries = [];

    /**
     * The bill worked out for each entered reading, or why it can't be issued, keyed by customer id.
     *
     * @var array<int|string, array{error?: string, consumption?: string, total?: string, note?: string}>
     */
    public array $previews = [];

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
        $this->readOn = $today;
    }

    public function updatedPeriod(): void
    {
        $this->reset('entries', 'previews');
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
        $this->recomputeAll();
    }

    /**
     * Works the row's bill out again as soon as its reading or defective tick changes.
     */
    public function updatedEntries(mixed $value, ?string $key = null): void
    {
        $id = $key === null ? null : self::idFrom(explode('.', $key)[0]);
        if ($id !== null) {
            $this->recompute($id);
        }
    }

    private function recomputeAll(): void
    {
        foreach (array_keys($this->entries) as $id) {
            $this->recompute((int) $id);
        }
    }

    private function recompute(int $customerId): void
    {
        $entry = $this->entries[$customerId] ?? ['present' => '', 'defective' => false];
        $present = trim((string) ($entry['present'] ?? ''));
        $defective = (bool) ($entry['defective'] ?? false);

        if ($present === '' && ! $defective) {
            unset($this->previews[$customerId]);

            return;
        }

        $customer = $this->company()->customers()->find($customerId);
        if ($customer === null) {
            unset($this->entries[$customerId], $this->previews[$customerId]);

            return;
        }

        try {
            $bill = BillingRun::compute($customer, $this->period, $present, $defective, $this->validDate($this->invoiceDate));
        } catch (BillingException $exception) {
            $this->previews[$customerId] = ['error' => $exception->getMessage()];

            return;
        }

        $notes = array_filter([
            $bill['estimated'] ? 'Estimated (defective meter)' : null,
            $bill['minimumApplied'] ? 'Minimum consumption billed' : null,
            $bill['replaced'] > 0 ? 'Includes '.BillCalculator::quantity($bill['replaced']).' cu.m. on the replaced meter' : null,
            $bill['fees']->isNotEmpty() ? 'Includes work order fees' : null,
            $bill['overdue']->isNotEmpty() ? 'Includes overdue surcharge' : null,
        ]);

        $this->previews[$customerId] = [
            'consumption' => BillCalculator::quantity($bill['billed']),
            'total' => Money::fromCents($bill['totalCents']),
            'note' => implode(' · ', $notes),
        ];
    }

    private function validDate(string $date): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) ? $date : BillingRun::today($this->company());
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
        foreach (['invoiceDate' => 'invoice date', 'readOn' => 'reading date'] as $field => $label) {
            $value = $this->{$field};
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || ! strtotime($value)) {
                $errors[$field] = "Enter the {$label}.";
            } elseif ($value > $today) {
                $errors[$field] = "The {$label} can't be in the future.";
            }
        }

        return $errors;
    }

    /**
     * Issues a bill for every customer with a reading entered (or marked defective). Rows that can't
     * be billed keep their reading and show why.
     */
    public function generate(): void
    {
        if (! $this->allowed(Access::Add)) {
            return;
        }

        $this->resetValidation();
        if ($errors = $this->settingsErrors()) {
            foreach ($errors as $key => $message) {
                $this->addError($key, $message);
            }

            return;
        }

        $issued = 0;
        $failed = 0;
        foreach ($this->entries as $id => $entry) {
            $present = trim((string) ($entry['present'] ?? ''));
            $defective = (bool) ($entry['defective'] ?? false);
            if ($present === '' && ! $defective) {
                continue;
            }

            $customer = $this->company()->customers()->find((int) $id);
            if ($customer === null) {
                continue;
            }

            try {
                BillingRun::generate($customer, $this->period, $present, $defective, $this->invoiceDate, $this->readOn, $this->user());
                unset($this->entries[$id], $this->previews[$id]);
                $issued++;
            } catch (BillingException $exception) {
                $this->previews[$id] = ['error' => $exception->getMessage()];
                $failed++;
            }
        }

        if ($issued === 0 && $failed === 0) {
            $this->flash('Enter at least one present reading (or tick Defective meter) first.', 'error');

            return;
        }

        $this->flash(trim(
            ($issued > 0 ? "{$issued} bill".($issued === 1 ? '' : 's').' generated for '.BillingRun::periodLabel($this->period).'. ' : '')
            .($failed > 0 ? "{$failed} could not be billed; see the messages in the table." : ''),
        ), $failed > 0 && $issued === 0 ? 'error' : 'success');
    }

    public function render(): View
    {
        $company = $this->company();
        $periodValid = BillingRun::periodError($this->period, BillingRun::today($company)) === null;

        $customers = BillingRun::candidates($company, self::idFrom($this->clusterId), trim($this->search))
            ->with(['rateType:id,name'])
            ->paginate(self::PER_PAGE);

        // What each listed customer's row shows besides the entered reading.
        $rows = [];
        foreach ($customers as $customer) {
            /** @var Customer $customer */
            $billed = $periodValid ? BillingRun::existingBill($customer, $this->period) : null;
            $history = $periodValid && $billed === null ? MeterHistory::for($customer, $this->period) : null;
            $disconnected = $customer->status === CustomerStatus::Disconnected;

            // A disconnected customer is billed up to the final reading taken at disconnection.
            if ($history !== null && $disconnected && $history['finalReading'] !== null && ! isset($this->entries[$customer->id])) {
                $this->entries[$customer->id] = ['present' => BillCalculator::quantity($history['finalReading']), 'defective' => false];
                $this->recompute($customer->id);
            }

            $rows[$customer->id] = [
                'billed' => $billed,
                'previous' => $history === null ? null : BillCalculator::quantity($history['previous']),
                'nothingToBill' => $disconnected && $history !== null && $history['finalReading'] === null,
            ];
        }

        return view('livewire.invoices.generate-bills-page', [
            'customers' => $customers,
            'rows' => $rows,
            'clusters' => $company->clusterTypes()->orderBy('name')->pluck('name', 'id')->all(),
            'periodLabel' => $periodValid ? BillingRun::periodLabel($this->period) : null,
            'dueDays' => $company->due_date_days ?? 0,
            'readyCount' => count(array_filter($this->previews, fn (array $preview): bool => ! isset($preview['error']))),
            'readyTotal' => Money::fromCents(array_sum(array_map(fn (array $preview): int => Money::toCents($preview['total'] ?? 0), $this->previews))),
            'today' => BillingRun::today($company),
            'access' => $this->access,
        ]);
    }
}
