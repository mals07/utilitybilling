<?php

namespace App\Livewire\Disconnections;

use App\Enums\Access;
use App\Enums\DisconnectionNoticeStatus;
use App\Enums\Page;
use App\Livewire\Concerns\GuardsPageAccess;
use App\Livewire\Concerns\ParsesIds;
use App\Livewire\Concerns\SearchesCustomers;
use App\Models\Customer;
use App\Models\DisconnectionNotice;
use App\Models\User;
use App\Support\Disconnections;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Disconnections: customers flagged for non-payment, notices of disconnection (issued, emailed and
 * printed in batches), Disconnection work orders for notices past their deadline, exemptions, and
 * the history of closed notices.
 */
#[Layout('layouts.app')]
#[Title('Disconnections')]
class DisconnectionsPage extends Component
{
    use GuardsPageAccess, ParsesIds, SearchesCustomers, WithPagination;

    public const PER_PAGE = 25;

    public const MINIMUM_ROWS = 8;

    /**
     * The most rows "Select all" picks at once.
     */
    public const SELECT_ALL_LIMIT = 500;

    /**
     * Tab => label. Eligible lists customers; On hold lists customers; the others list notices.
     */
    public const TABS = [
        'eligible' => 'Eligible',
        'sent' => 'Notice sent',
        'due' => 'Due for disconnection',
        'field' => 'Work order created',
        'hold' => 'On hold',
        'history' => 'History',
    ];

    #[Url(as: 'tab', except: 'eligible')]
    public string $tab = 'eligible';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'cluster', except: '')]
    public string $clusterId = '';

    /**
     * Ids ticked in the table: customers on Eligible, notices on the notice tabs.
     *
     * @var list<string>
     */
    public array $selected = [];

    /**
     * Email the notices being issued to customers with an email address.
     */
    public bool $emailNotices = true;

    /**
     * Who the Disconnection work orders being created are assigned to.
     */
    public string $assigneeId = '';

    /**
     * Notices just issued, offered for printing.
     *
     * @var list<int>
     */
    public array $justIssued = [];

    /**
     * The notice being cancelled.
     */
    public string $cancellingId = '';

    public string $cancelReason = '';

    /**
     * Skipped work orders and why, shown after creating them.
     *
     * @var list<string>
     */
    public array $skipped = [];

    public string $exemptReason = '';

    public string $exemptUntil = '';

    protected function page(): Page
    {
        return Page::Disconnections;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['tab', 'search', 'clusterId'], true)) {
            $this->resetPage();
            $this->selected = [];
        }
        if ($property === 'tab') {
            $this->skipped = [];
            $this->tab = array_key_exists($this->tab, self::TABS) ? $this->tab : 'eligible';
        }
        if (in_array($property, ['cancelReason', 'exemptReason', 'exemptUntil', 'assigneeId'], true)) {
            $this->resetValidation($property);
        }
    }

    private function today(): string
    {
        return $this->company()->today();
    }

    /* --------------------------------- Queries -------------------------------- */

    /**
     * Narrows a customers query to the search and cluster.
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    private function filterCustomers(Builder $query): Builder
    {
        $search = trim($this->search);
        $clusterId = self::idFrom($this->clusterId);

        return $query
            ->when($clusterId !== null, fn (Builder $query) => $query->where('cluster_type_id', $clusterId))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('account_number', 'like', "%{$search}%")
                ->orWhere('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('meter_number', 'like', "%{$search}%")));
    }

    /**
     * The notices listed on a notice tab.
     *
     * @return Builder<DisconnectionNotice>
     */
    private function noticesFor(string $tab): Builder
    {
        $today = $this->today();

        return $this->company()->disconnectionNotices()->getQuery()
            ->whereHas('customer', fn (Builder $query) => $this->filterCustomers($query))
            ->when($tab === 'sent', fn (Builder $query) => $query->where('status', DisconnectionNoticeStatus::Pending)->whereDate('deadline', '>=', $today))
            ->when($tab === 'due', fn (Builder $query) => $query->where('status', DisconnectionNoticeStatus::Pending)->whereDate('deadline', '<', $today))
            ->when($tab === 'field', fn (Builder $query) => $query->where('status', DisconnectionNoticeStatus::Scheduled))
            ->when($tab === 'history', fn (Builder $query) => $query->whereNotIn('status', DisconnectionNoticeStatus::openValues()));
    }

    /**
     * @return Builder<Customer>
     */
    private function eligibleCustomers(): Builder
    {
        return $this->filterCustomers(Disconnections::eligible($this->company(), $this->today()));
    }

    /**
     * Rows of the current tab, for selecting and printing.
     *
     * @return Collection<int, int>
     */
    private function idsOnTab(): Collection
    {
        return match ($this->tab) {
            'eligible' => $this->eligibleCustomers()->limit(self::SELECT_ALL_LIMIT)->pluck('customers.id'),
            'sent', 'due', 'field' => $this->noticesFor($this->tab)->orderBy('id')->limit(self::SELECT_ALL_LIMIT)->pluck('id'),
            default => collect(),
        };
    }

    /**
     * How many customers or notices each tab has, ignoring the search and cluster.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        $company = $this->company();
        $today = $this->today();
        $pending = fn () => $company->disconnectionNotices()->where('status', DisconnectionNoticeStatus::Pending);

        return [
            'eligible' => Disconnections::eligible($company, $today)->count(),
            'sent' => $pending()->whereDate('deadline', '>=', $today)->count(),
            'due' => $pending()->whereDate('deadline', '<', $today)->count(),
            'field' => $company->disconnectionNotices()->where('status', DisconnectionNoticeStatus::Scheduled)->count(),
            'hold' => Disconnections::onHold($company, $today)->count(),
        ];
    }

    /* -------------------------------- Selecting ------------------------------- */

    public function selectAll(): void
    {
        $this->selected = $this->idsOnTab()->map(fn (int $id): string => (string) $id)->all();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    /**
     * @return list<int>
     */
    private function selectedIds(): array
    {
        return array_values(array_filter(array_map(fn (string $id): ?int => self::idFrom($id), $this->selected)));
    }

    /* --------------------------------- Notices -------------------------------- */

    /**
     * Issue a notice of disconnection to the ticked customers on Eligible.
     */
    public function issueNotices(): void
    {
        if (! $this->allowed(Access::Add) || $this->tab !== 'eligible') {
            return;
        }

        $ids = $this->selectedIds();
        if ($ids === []) {
            $this->flash('Tick the customers to send a notice to.', 'error');

            return;
        }

        $result = Disconnections::issue($this->company(), $ids, $this->user(), $this->emailNotices);
        $issued = collect($result['issued']);
        $emailed = $issued->whereNotNull('emailed_at')->count();

        $this->justIssued = $issued->pluck('id')->all();
        $this->selected = [];
        unset($this->counts);
        $this->resetPage();

        $message = "Issued {$issued->count()} ".str('notice')->plural($issued->count()).'.';
        if ($this->emailNotices) {
            $message .= " {$emailed} emailed".($issued->count() > $emailed ? ', the rest have no email address' : '').'.';
        }
        if ($result['skipped'] > 0) {
            $message .= " {$result['skipped']} skipped: no longer eligible.";
        }
        $this->flash($message, $issued->isEmpty() ? 'error' : 'success');
    }

    /**
     * Email the ticked notices again (or for the first time).
     */
    public function emailSelected(): void
    {
        if (! $this->allowed(Access::Add)) {
            return;
        }

        $notices = $this->company()->disconnectionNotices()
            ->whereIn('id', $this->selectedIds())
            ->whereIn('status', DisconnectionNoticeStatus::openValues())
            ->with(['customer', 'company'])
            ->get();

        $sent = $notices->filter(fn (DisconnectionNotice $notice): bool => Disconnections::email($notice))->count();
        $missing = $notices->count() - $sent;

        $this->flash($notices->isEmpty()
            ? 'Tick the notices to email.'
            : "Emailed {$sent} ".str('notice')->plural($sent).'.'.($missing > 0 ? " {$missing} skipped: no email address." : ''), $sent > 0 ? 'success' : 'error');
    }

    public function dismissIssued(): void
    {
        $this->justIssued = [];
    }

    #[Computed]
    public function cancelling(): ?DisconnectionNotice
    {
        $id = self::idFrom($this->cancellingId);

        return $id === null ? null : $this->company()->disconnectionNotices()
            ->whereIn('status', DisconnectionNoticeStatus::openValues())
            ->with(['customer', 'workOrder'])
            ->find($id);
    }

    public function askCancel(int $id): void
    {
        $this->cancellingId = (string) $id;
        $this->cancelReason = '';
        unset($this->cancelling);
        $this->resetValidation('cancelReason');
    }

    public function closeCancel(): void
    {
        $this->reset('cancellingId', 'cancelReason');
        unset($this->cancelling);
        $this->resetValidation('cancelReason');
    }

    /**
     * Withdraw the notice (and cancel its work order), with the reason given.
     */
    public function cancelNotice(): void
    {
        if (! $this->allowed(Access::Delete)) {
            return;
        }

        $reason = trim($this->cancelReason);
        if ($reason === '' || mb_strlen($reason) > 200) {
            $this->addError('cancelReason', $reason === '' ? 'Give a reason.' : 'The reason must not be greater than 200 characters.');

            return;
        }

        $notice = $this->cancelling;
        if ($notice === null) {
            $this->closeCancel();

            return;
        }

        Disconnections::cancel($notice, $reason, $this->user());

        $this->closeCancel();
        unset($this->counts);
        $this->selected = array_values(array_diff($this->selected, [(string) $notice->id]));
        $this->flash("Notice {$notice->notice_number} cancelled.".($notice->workOrder ? " Work order {$notice->workOrder->order_number} was cancelled too." : ''));
    }

    /* ------------------------------- Work orders ------------------------------ */

    /**
     * Active users of the company, narrowed to the work orders' default role when one is chosen.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function assignees(): array
    {
        $company = $this->company();
        $roleId = $company->work_order_assignee_role_id;

        return $company->members()
            ->whereNull('deactivated_at')
            ->when($roleId !== null, fn (Builder $query) => $query->where('role_id', $roleId))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'name', 'first_name', 'last_name'])
            ->mapWithKeys(fn (User $user): array => [$user->id => $user->name ?: User::fullName($user->first_name, $user->last_name)])
            ->all();
    }

    /**
     * Create Disconnection work orders for the ticked notices on Due for disconnection.
     */
    public function createWorkOrders(): void
    {
        if (! $this->allowed(Access::Add) || $this->tab !== 'due') {
            return;
        }

        $ids = $this->selectedIds();
        if ($ids === []) {
            $this->flash('Tick the notices to create work orders for.', 'error');

            return;
        }

        $assigneeId = self::idFrom($this->assigneeId);
        if ($this->assigneeId !== '' && ($assigneeId === null || ! array_key_exists($assigneeId, $this->assignees))) {
            $this->addError('assigneeId', 'Choose who the work orders are assigned to from the list.');

            return;
        }

        $result = Disconnections::createWorkOrders($this->company(), $ids, $assigneeId, $this->user());
        $created = count($result['created']);

        $this->skipped = $result['skipped'];
        $this->selected = [];
        unset($this->counts);
        $this->resetPage();

        $this->flash("Created {$created} Disconnection work ".str('order')->plural($created).'.'
            .($this->skipped !== [] ? ' '.count($this->skipped).' skipped; see the list below.' : ''), $created > 0 ? 'success' : 'error');
    }

    /* -------------------------------- Exemptions ------------------------------- */

    /**
     * Exempt the customer chosen in the On hold tab from disconnection.
     */
    public function exempt(): void
    {
        if (! $this->allowed(Access::Edit)) {
            return;
        }

        $this->resetValidation();
        unset($this->chosenCustomer);
        $customer = $this->chosenCustomer;
        $reason = trim($this->exemptReason);
        $until = trim($this->exemptUntil);

        $errors = [];
        if ($customer === null) {
            $errors['customerId'] = 'Search for the customer and pick them from the list.';
        }
        if ($reason === '' || mb_strlen($reason) > 200) {
            $errors['exemptReason'] = $reason === '' ? 'Give the reason, e.g. Hospital.' : 'The reason must not be greater than 200 characters.';
        }
        if ($until !== '' && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) || ! strtotime($until))) {
            $errors['exemptUntil'] = 'Enter a date, or leave it blank for no end date.';
        } elseif ($until !== '' && $until < $this->today()) {
            $errors['exemptUntil'] = "The end date can't be in the past.";
        }

        if ($errors !== []) {
            foreach ($errors as $key => $message) {
                $this->addError($key, $message);
            }

            return;
        }

        Disconnections::exempt($customer, $reason, $until === '' ? null : $until, $this->user());

        $this->clearCustomer();
        $this->reset('exemptReason', 'exemptUntil');
        unset($this->counts);
        $this->flash("{$customer->fullName()} is exempt from disconnection".($until === '' ? '.' : ' until '.date('m/d/Y', strtotime($until)).'.'));
    }

    public function removeExemption(int $id): void
    {
        if (! $this->allowed(Access::Edit)) {
            return;
        }

        $customer = $this->company()->customers()->find($id);
        if ($customer === null) {
            return;
        }

        Disconnections::removeExemption($customer);
        unset($this->counts);
        $this->flash("{$customer->fullName()} is no longer exempt from disconnection.");
    }

    public function render(): View
    {
        $company = $this->company();
        $today = $this->today();
        $tab = $this->tab;

        $rows = match ($tab) {
            'eligible' => $this->paginateClamped(
                $this->eligibleCustomers()->with('clusterType:id,name')->orderBy('oldest_due_date')->orderBy('account_number'),
                self::PER_PAGE,
            ),
            'hold' => $this->paginateClamped(
                $this->filterCustomers(Disconnections::onHold($company, $today))->with('clusterType:id,name')->orderBy('account_number'),
                self::PER_PAGE,
            ),
            default => $this->paginateClamped(
                $this->noticesFor($tab)
                    ->with(['customer.clusterType:id,name', 'workOrder.assignedTo:id,name', 'issuedBy:id,name', 'closedBy:id,name'])
                    ->when($tab === 'history', fn (Builder $query) => $query->orderByDesc('closed_at')->orderByDesc('id'), fn (Builder $query) => $query->orderBy('deadline')->orderBy('id')),
                self::PER_PAGE,
            ),
        };

        $skipDays = array_map(fn (int $day): string => date('D', strtotime("Sunday +{$day} days")), $company->disconnectionSkipDays());

        return view('livewire.disconnections.disconnections-page', [
            'company' => $company,
            'today' => $today,
            'rows' => $rows,
            'counts' => $this->counts,
            'clusters' => $company->clusterTypes()->orderBy('name')->pluck('name', 'id')->all(),
            'assignees' => $tab === 'due' ? $this->assignees : [],
            'exempt' => $tab === 'hold' ? Disconnections::exemptCustomers($company, $today) : collect(),
            'holdReasons' => $tab === 'hold'
                ? $rows->getCollection()->mapWithKeys(fn (Customer $customer): array => [$customer->id => Disconnections::holdReason($customer, $today)])->all()
                : [],
            'chosenCustomer' => $tab === 'hold' ? $this->chosenCustomer : null,
            'customerMatches' => $tab === 'hold' ? $this->customerMatches : collect(),
            'printIds' => in_array($tab, ['sent', 'due', 'field'], true) ? $this->idsOnTab()->implode(',') : '',
            'skipDays' => $skipDays,
            'cancelling' => $this->cancelling,
            'access' => $this->access,
        ]);
    }
}
