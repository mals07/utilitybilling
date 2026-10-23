@use('App\Livewire\Disconnections\DisconnectionsPage')
@use('App\Support\Disconnections')
@use('App\Support\Format')

@php
    $noticeTab = in_array($tab, ['sent', 'due', 'field', 'history'], true);
    $selectable = in_array($tab, ['eligible', 'sent', 'due', 'field'], true);
    $columns = match ($tab) {
        'eligible' => 5,
        'hold' => 6,
        'history' => 6,
        default => 7,
    };
    // The Cluster column hides on narrow screens; it follows the optional Select and Notice columns and Customer.
    $hideClusterOnPhones = match (2 + ($selectable ? 1 : 0) + ($noticeTab ? 1 : 0)) {
        2 => 'max-md:[&_tr>*:nth-child(2)]:hidden',
        3 => 'max-md:[&_tr>*:nth-child(3)]:hidden',
        default => 'max-md:[&_tr>*:nth-child(4)]:hidden',
    };
@endphp

<x-page title="Disconnections" guide="disconnections">
    <div>
        <x-flash class="mb-4" />

        <p class="text-[13px] text-label">
            Customers are flagged with <span class="font-semibold text-ink-strong">{{ $company->disconnection_overdue_bills }} or more overdue bills</span>.
            Notices give <span class="font-semibold text-ink-strong">{{ $company->disconnection_notice_days }} working days</span> to pay{{ $skipDays !== [] ? ', not counting '.implode(', ', $skipDays) : '' }}.
            @if (auth()->user()->canAccessPage(\App\Enums\Page::CompanySetup))
                <a href="{{ route('company', ['tab' => 'operation-setup']) }}" class="link-button ml-1">Change</a>
            @endif
        </p>

        {{-- Step tiles --}}
        <div class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
            @foreach (['eligible' => 'Ready for a notice', 'sent' => 'Waiting for payment', 'due' => 'Deadline passed', 'field' => 'With the field crew', 'hold' => 'Exempt or disputing'] as $key => $hint)
                <button
                    type="button"
                    wire:click="$set('tab', '{{ $key }}')"
                    aria-pressed="{{ $tab === $key ? 'true' : 'false' }}"
                    @class(['rounded-md px-4 py-3 text-left', 'bg-row-selected ring-1 ring-accent' => $tab === $key, 'bg-panel hover:bg-row-selected' => $tab !== $key])
                >
                    <p class="text-xs text-label">{{ DisconnectionsPage::TABS[$key] }}</p>
                    <p @class(['mt-0.5 text-xl font-semibold', 'text-danger' => $key === 'due' && $counts[$key] > 0, 'text-ink-strong' => ! ($key === 'due' && $counts[$key] > 0)])>{{ Format::integer($counts[$key]) }}</p>
                    <p class="mt-1 text-[11px] text-label">{{ $hint }}</p>
                </button>
            @endforeach
        </div>

        {{-- Tabs and filters --}}
        <div class="mt-5 flex flex-wrap items-center gap-2">
            @foreach (DisconnectionsPage::TABS as $value => $label)
                <button
                    type="button"
                    wire:click="$set('tab', '{{ $value }}')"
                    aria-pressed="{{ $tab === $value ? 'true' : 'false' }}"
                    @class([
                        'h-7 rounded-full border px-3 text-xs',
                        'border-accent bg-accent text-white' => $tab === $value,
                        'border-line text-ink-soft hover:border-line-hover' => $tab !== $value,
                    ])
                >
                    {{ $label }}
                    @isset($counts[$value])
                        <span @class(['ml-0.5', 'text-white/70' => $tab === $value, 'text-placeholder' => $tab !== $value])>{{ Format::integer($counts[$value]) }}</span>
                    @endisset
                </button>
            @endforeach
            <div class="ml-auto flex flex-wrap items-center gap-2">
                <label for="dc-cluster" class="sr-only">Cluster</label>
                <select id="dc-cluster" wire:model.live="clusterId" class="form-control form-control-sm w-44 !text-[13px]">
                    <option value="">All clusters</option>
                    @foreach ($clusters as $id => $name)
                        <option value="{{ $id }}" wire:key="cluster-{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
                <x-search-box label="Search account, name or meter" :width="220" wire:model.live.debounce.300ms="search" />
            </div>
        </div>

        {{-- Notices just issued --}}
        @if ($justIssued !== [])
            <x-alert tone="success" class="mt-4">
                <span class="flex flex-wrap items-center gap-3">
                    {{ count($justIssued) }} {{ Str::plural('notice', count($justIssued)) }} issued.
                    <a href="{{ route('disconnections.print', ['notices' => implode(',', $justIssued)]) }}" target="_blank" rel="noopener" class="link-button">Print them</a>
                    <button type="button" class="link-button" wire:click="dismissIssued">Dismiss</button>
                </span>
            </x-alert>
        @endif

        {{-- Actions for the tab --}}
        @if ($selectable)
            <div class="mt-4 flex flex-wrap items-end gap-3 rounded-md border border-table-line bg-panel px-4 py-3 text-[13px]" wire:loading.class="opacity-60" wire:target="issueNotices,createWorkOrders,emailSelected">
                <p class="mr-auto">
                    <span class="font-semibold text-ink-strong">{{ count($selected) }}</span> selected
                    <button type="button" class="link-button ml-2" wire:click="selectAll">Select all</button>
                    @if ($selected !== [])
                        <button type="button" class="link-button ml-2" wire:click="clearSelection">Clear</button>
                    @endif
                </p>

                @if ($tab === 'eligible' && $access['add'])
                    <label class="check !text-[13px]">
                        <input type="checkbox" wire:model="emailNotices">
                        Email customers with an email address
                    </label>
                    <button type="button" class="form-button form-button-sm min-w-36" wire:click="issueNotices" wire:loading.attr="disabled" wire:target="issueNotices" @disabled($selected === [])>
                        Issue Notices
                    </button>
                @endif

                @if (in_array($tab, ['sent', 'due', 'field'], true))
                    @if ($selected !== [])
                        <a href="{{ route('disconnections.print', ['notices' => implode(',', $selected)]) }}" target="_blank" rel="noopener" class="link-button">Print selected</a>
                    @endif
                    @if ($printIds !== '')
                        <a href="{{ route('disconnections.print', ['notices' => $printIds]) }}" target="_blank" rel="noopener" class="link-button">Print all</a>
                    @endif
                    @if ($access['add'] && $tab !== 'field')
                        <button type="button" class="link-button" wire:click="emailSelected" @disabled($selected === [])>Email selected</button>
                    @endif
                @endif

                @if ($tab === 'due' && $access['add'])
                    <div>
                        <label for="dc-assignee" class="mb-1 block text-xs font-semibold text-ink-strong">Assign to</label>
                        <select id="dc-assignee" wire:model="assigneeId" class="form-control form-control-sm w-48 !text-[13px]" @error('assigneeId') aria-invalid="true" @enderror>
                            <option value="">Nobody yet</option>
                            @foreach ($assignees as $id => $name)
                                <option value="{{ $id }}" wire:key="assignee-{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                        @error('assigneeId') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <button type="button" class="form-button form-button-sm min-w-44" wire:click="createWorkOrders" wire:loading.attr="disabled" wire:target="createWorkOrders" @disabled($selected === [])>
                        Create Work Orders
                    </button>
                @endif
            </div>

            @if ($tab === 'due')
                <p class="mt-2 text-xs text-label">Each customer's balance is checked again before its work order is created. Paying later cancels the work order automatically.</p>
            @endif
        @endif

        @if ($skipped !== [] && $tab === 'due')
            <x-alert tone="error" class="mt-3">
                <p class="font-semibold">Not created:</p>
                <ul class="mt-1 list-disc pl-5">
                    @foreach ($skipped as $line)
                        <li wire:key="skipped-{{ $loop->index }}">{{ $line }}</li>
                    @endforeach
                </ul>
            </x-alert>
        @endif

        {{-- Exempt a customer --}}
        @if ($tab === 'hold' && $access['edit'])
            <form wire:submit="exempt" novalidate class="mt-4 grid gap-3 rounded-md border border-table-line bg-panel px-4 py-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_160px_auto] md:items-start">
                @include('livewire.partials.customer-search', ['fieldId' => 'exempt-customer', 'label' => 'Exempt a customer'])
                <div>
                    <label for="exempt-reason" class="mb-1 block text-xs font-semibold text-ink-strong">Reason<x-required-mark /></label>
                    <input id="exempt-reason" aria-required="true" type="text" maxlength="200" wire:model="exemptReason" placeholder="e.g. Hospital, Government" class="form-control !h-[34px] !text-[13px]" @error('exemptReason') aria-invalid="true" aria-describedby="exempt-reason-error" @enderror>
                    @error('exemptReason') <p id="exempt-reason-error" class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="exempt-until" class="mb-1 block text-xs font-semibold text-ink-strong">Until (optional)</label>
                    <input id="exempt-until" type="date" min="{{ $today }}" wire:model="exemptUntil" class="form-control !h-[34px] !text-[13px]" @error('exemptUntil') aria-invalid="true" aria-describedby="exempt-until-error" @enderror>
                    @error('exemptUntil') <p id="exempt-until-error" class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="md:pt-5">
                    <button type="submit" class="form-button form-button-sm min-w-24" wire:loading.attr="disabled" wire:target="exempt">Exempt</button>
                </div>
            </form>
        @endif

        {{-- List --}}
        <div class="table-scroller mt-3">
            <table class="data-table {{ $hideClusterOnPhones }}">
                <thead>
                    <tr>
                        @if ($selectable)
                            <th scope="col" class="w-8"><span class="sr-only">Select</span></th>
                        @endif
                        @if ($noticeTab)
                            <th scope="col" class="w-px">Notice</th>
                        @endif
                        <th scope="col">Customer</th>
                        <th scope="col">Cluster</th>
                        @if ($tab === 'eligible' || $tab === 'hold')
                            <th scope="col" class="cell-right">Overdue bills</th>
                            <th scope="col" class="cell-right">Overdue amount</th>
                            <th scope="col">{{ $tab === 'hold' ? 'On hold because' : 'Oldest due' }}</th>
                        @elseif ($tab === 'history')
                            <th scope="col">Issued</th>
                            <th scope="col">Result</th>
                            <th scope="col">Why</th>
                        @else
                            <th scope="col" class="cell-right">Overdue amount</th>
                            <th scope="col">Deadline</th>
                            <th scope="col">{{ $tab === 'field' ? 'Work order' : 'Sent' }}</th>
                        @endif
                        @if ($tab !== 'eligible' && $tab !== 'history')
                            <th scope="col" class="cell-center"><span class="sr-only">Actions</span></th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php($customer = $noticeTab ? $row->customer : $row)
                        <tr wire:key="dc-{{ $tab }}-{{ $row->id }}" @class(['row-selected' => in_array((string) $row->id, $selected, true)])>
                            @if ($selectable)
                                <td>
                                    <input type="checkbox" wire:model.live="selected" value="{{ $row->id }}" aria-label="Select {{ $customer->account_number }}">
                                </td>
                            @endif
                            @if ($noticeTab)
                                <td class="font-medium whitespace-nowrap text-ink-strong tabular-nums">{{ $row->notice_number }}</td>
                            @endif
                            <td>
                                <span class="block font-medium text-ink-strong">{{ $customer->fullName() }}</span>
                                <span class="mt-0.5 block text-xs whitespace-nowrap text-label tabular-nums">Acct No: {{ $customer->account_number }}</span>
                            </td>
                            <td>{{ $customer->clusterType?->name }}</td>

                            @if ($tab === 'eligible' || $tab === 'hold')
                                <td class="cell-right">{{ $row->overdue_bills }}</td>
                                <td class="cell-right font-semibold text-ink-strong">{{ Format::amount($row->overdue_amount ?? 0) }}</td>
                                @if ($tab === 'hold')
                                    <td class="text-warning">{{ ucfirst((string) $holdReasons[$row->id]) }}</td>
                                @else
                                    <td class="text-xs whitespace-nowrap text-label tabular-nums">{{ Format::shortDate(\Illuminate\Support\Carbon::parse($row->oldest_due_date)) }}</td>
                                @endif
                            @elseif ($tab === 'history')
                                <td class="text-xs whitespace-nowrap text-label tabular-nums">{{ Format::shortDate($row->issued_on) }}</td>
                                <td>
                                    <span class="status-pill {{ $row->status->colorClass() }}">{{ $row->status->label() }}</span>
                                    @if ($row->closed_at)
                                        <span class="mt-1 block text-xs text-label tabular-nums">{{ Format::shortDateIn($row->closed_at, $timezone) }}</span>
                                    @endif
                                </td>
                                <td class="text-ink-soft">
                                    <span class="block">{{ $row->close_reason }}</span>
                                    @if ($row->closedBy)
                                        <span class="mt-0.5 block text-xs text-label">by {{ $row->closedBy->name }}</span>
                                    @endif
                                </td>
                            @else
                                <td class="cell-right font-semibold text-ink-strong">{{ Format::amount($row->overdue_amount) }}</td>
                                <td class="text-xs whitespace-nowrap tabular-nums">
                                    <span class="block text-label">{{ Format::shortDate($row->deadline) }}</span>
                                    @if ($tab === 'sent')
                                        @php($left = Disconnections::workingDaysLeft($company, $today, $row->deadline->toDateString()))
                                        <span @class(['mt-0.5 block', 'text-label' => $left > 0, 'font-semibold text-warning' => $left === 0])>{{ $left > 0 ? $left.' '.Str::plural('day', $left).' left' : 'Last day' }}</span>
                                    @endif
                                </td>
                                <td class="text-[13px]">
                                    @if ($tab === 'field')
                                        <a href="{{ route('work-orders', ['q' => $row->workOrder?->order_number]) }}" class="link-button">{{ $row->workOrder?->order_number }}</a>
                                        <span class="text-label">· {{ $row->workOrder?->assignedTo?->name ?? 'Unassigned' }}</span>
                                    @else
                                        <span class="text-label">
                                            {{ collect([$row->emailed_at ? 'Emailed' : null, $row->printed_at ? 'Printed' : null])->filter()->implode(', ') ?: 'Not delivered yet' }}
                                        </span>
                                    @endif
                                </td>
                            @endif

                            @if ($tab === 'hold')
                                <td class="cell-actions"></td>
                            @elseif ($tab !== 'eligible' && $tab !== 'history')
                                <td class="cell-actions">
                                    @if ($access['delete'])
                                        <button type="button" class="link-button text-danger" wire:click="askCancel({{ $row->id }})">Cancel</button>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                    <x-filler-rows
                        :count="$rows->count()"
                        :minimum="DisconnectionsPage::MINIMUM_ROWS"
                        :columns="$columns + ($selectable ? 1 : 0)"
                        :text-column="($selectable ? 1 : 0) + ($noticeTab ? 1 : 0)"
                        :empty-text="match ($tab) {
                            'eligible' => 'No customers are overdue enough for a notice.',
                            'sent' => 'No notices are waiting for payment.',
                            'due' => 'No notices are past their deadline.',
                            'field' => 'No disconnection work orders are open.',
                            'hold' => 'No overdue customers are on hold.',
                            default => 'No closed notices yet.',
                        }"
                    />
                </tbody>
            </table>
        </div>

        <x-pagination :paginator="$rows" />

        {{-- Exemptions in force --}}
        @if ($tab === 'hold')
            <h2 class="mt-6 text-[13px] font-semibold text-ink-strong">Exempt customers</h2>
            <p class="text-xs text-label">Never flagged for disconnection while the exemption lasts, whatever they owe.</p>
            <div class="table-scroller mt-2">
                <table class="data-table table-compact">
                    <thead>
                        <tr>
                            <th scope="col">Customer</th>
                            <th scope="col">Reason</th>
                            <th scope="col">Until</th>
                            <th scope="col">By</th>
                            <th scope="col" class="cell-center"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($exempt as $customer)
                            <tr wire:key="exempt-{{ $customer->id }}">
                                <td>
                                    <span class="block font-medium text-ink-strong">{{ $customer->fullName() }}</span>
                                    <span class="block text-xs whitespace-nowrap text-label tabular-nums">Acct No: {{ $customer->account_number }}</span>
                                </td>
                                <td>{{ $customer->disconnection_exempt_reason }}</td>
                                <td class="text-xs whitespace-nowrap text-label tabular-nums">{{ $customer->disconnection_exempt_until ? Format::shortDate($customer->disconnection_exempt_until) : 'No end date' }}</td>
                                <td>{{ $customer->disconnectionExemptedBy?->name }}</td>
                                <td class="cell-actions">
                                    @if ($access['edit'])
                                        <button type="button" class="link-button text-danger" wire:click="removeExemption({{ $customer->id }})" wire:confirm="Remove the exemption of {{ $customer->fullName() }}?">Remove</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-label">No exempt customers.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @if ($cancelling)
        <x-modal :title="'Cancel notice '.$cancelling->notice_number" close="$wire.closeCancel()" width="md">
            <form wire:submit="cancelNotice" novalidate class="mt-3 text-[13px]">
                <p>
                    {{ $cancelling->customer->fullName() }} ({{ $cancelling->customer->account_number }}) will not be disconnected on this notice.
                    @if ($cancelling->workOrder?->status->isOpen())
                        Work order <span class="font-semibold">{{ $cancelling->workOrder->order_number }}</span> will be cancelled too.
                    @endif
                </p>
                <label for="cancel-reason" class="mt-3 block text-xs font-semibold text-ink-strong">Why?<x-required-mark /></label>
                <textarea id="cancel-reason" aria-required="true" wire:model="cancelReason" rows="2" maxlength="200" class="form-control mt-1 !text-[13px]" placeholder="e.g. Promised to pay on Friday" @error('cancelReason') aria-invalid="true" aria-describedby="cancel-reason-error" @enderror></textarea>
                @error('cancelReason') <p id="cancel-reason-error" class="form-error" role="alert">{{ $message }}</p> @enderror
                <div class="form-actions mt-4">
                    <button type="button" class="text-button" wire:click="closeCancel">Back</button>
                    <button type="submit" class="form-button min-w-[140px]">Cancel Notice</button>
                </div>
            </form>
        </x-modal>
    @endif
</x-page>
