<?php

namespace App\Livewire\MeterReadings;

use App\Enums\Access;
use App\Enums\Page;
use App\Livewire\Concerns\GuardsPageAccess;
use App\Models\ClusterType;
use App\Models\ReadingAssignment;
use App\Models\User;
use App\Support\Billing\BillingRun;
use App\Support\Billing\MeterReadings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Reader Assignments: which meter reader reads each cluster in a billing month with the meter reader
 * app. A reader's phone downloads the customers of the clusters assigned to them.
 */
#[Layout('layouts.app')]
#[Title('Reader Assignments')]
class ReadingAssignmentsPage extends Component
{
    use GuardsPageAccess;

    #[Url(as: 'period', except: '')]
    public string $period = '';

    protected function page(): Page
    {
        return Page::MeterReadings;
    }

    public function mount(): void
    {
        if (BillingRun::periodError($this->period, BillingRun::today($this->company())) !== null) {
            $this->period = substr(BillingRun::today($this->company()), 0, 7);
        }
    }

    /**
     * Assigns the cluster to a reader for the month, or clears it when $userId is empty.
     */
    public function assign(int $clusterId, string $userId): void
    {
        if (! $this->allowed(Access::Edit)) {
            return;
        }

        $company = $this->company();
        $cluster = ClusterType::query()->where('company_id', $company->id)->find($clusterId);
        if ($cluster === null || BillingRun::periodError($this->period, BillingRun::today($company)) !== null) {
            return;
        }

        if ($userId === '') {
            ReadingAssignment::query()->where('billing_period', $this->period)->where('cluster_type_id', $cluster->id)->delete();
            $this->flash("{$cluster->name} is no longer assigned.");

            return;
        }

        $reader = $this->readers()->firstWhere('id', (int) $userId);
        if ($reader === null) {
            $this->flash('Choose a reader who may enter meter readings.', 'error');

            return;
        }

        ReadingAssignment::query()->updateOrCreate(
            ['company_id' => $company->id, 'billing_period' => $this->period, 'cluster_type_id' => $cluster->id],
            ['user_id' => $reader->id, 'assigned_by_id' => $this->user()->id],
        );
        $this->flash("{$cluster->name} assigned to {$reader->name}.");
    }

    /**
     * Active users who may use the meter reader app (Add on Meter Readings).
     *
     * @return Collection<int, User>
     */
    private function readers(): Collection
    {
        return User::query()
            ->where('company_id', $this->company()->id)
            ->whereNull('deactivated_at')
            ->whereNotNull('password')
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => $user->canAccessPage(Page::MeterReadings, Access::Add))
            ->values();
    }

    public function render(): View
    {
        $company = $this->company();
        $periodValid = BillingRun::periodError($this->period, BillingRun::today($company)) === null;

        $meterCounts = $periodValid
            ? MeterReadings::customers($company, $this->period, null, '')->toBase()
                ->select('customers.cluster_type_id')
                ->selectRaw('count(*) as meters')
                ->groupBy('cluster_type_id')
                ->pluck('meters', 'cluster_type_id')
            : collect();

        return view('livewire.meter-readings.reading-assignments-page', [
            'clusters' => ClusterType::query()->where('company_id', $company->id)->orderBy('name')->get(),
            'assignments' => ReadingAssignment::query()->where('billing_period', $this->period)->pluck('user_id', 'cluster_type_id'),
            'meterCounts' => $meterCounts,
            'readers' => $this->readers(),
            'periodLabel' => $periodValid ? BillingRun::periodLabel($this->period) : null,
            'today' => BillingRun::today($company),
        ]);
    }
}
