@use('App\Enums\ReportFilter')

@php
    $page = $report::page();
    $query = $filters->query();
    $uses = collect($uses);
@endphp

<x-page :title="$report->title()">
    <div class="grid gap-5">
        {{-- Filters --}}
        <section aria-label="Filters" class="flex flex-col gap-3 rounded-xl border border-table-line bg-card p-4 shadow-card lg:flex-row lg:items-end lg:justify-between">
            <div class="flex flex-wrap items-end gap-2">
                @if ($uses->contains(ReportFilter::Dates))
                    <div>
                        <label for="report-from" class="mb-1 block text-caption font-semibold text-ink-strong">From</label>
                        <input id="report-from" type="date" max="{{ $today }}" value="{{ $filters->from }}" wire:change="$set('from', $event.target.value)" class="form-control form-control-sm w-40 !text-body">
                    </div>
                    <div>
                        <label for="report-to" class="mb-1 block text-caption font-semibold text-ink-strong">To</label>
                        <input id="report-to" type="date" max="{{ $today }}" value="{{ $filters->to }}" wire:change="$set('to', $event.target.value)" class="form-control form-control-sm w-40 !text-body">
                    </div>
                @endif
                @if ($uses->contains(ReportFilter::Periods))
                    <div>
                        <label for="report-from-period" class="mb-1 block text-caption font-semibold text-ink-strong">From month</label>
                        <input id="report-from-period" type="month" max="{{ $currentPeriod }}" value="{{ $filters->fromPeriod }}" wire:change="$set('fromPeriod', $event.target.value)" class="form-control form-control-sm w-40 !text-body">
                    </div>
                    <div>
                        <label for="report-period" class="mb-1 block text-caption font-semibold text-ink-strong">To month</label>
                        <input id="report-period" type="month" max="{{ $currentPeriod }}" value="{{ $filters->period }}" wire:change="$set('period', $event.target.value)" class="form-control form-control-sm w-40 !text-body">
                    </div>
                @endif
                @if ($uses->contains(ReportFilter::Period))
                    <div>
                        <label for="report-period" class="mb-1 block text-caption font-semibold text-ink-strong">Billing month</label>
                        <input id="report-period" type="month" max="{{ $currentPeriod }}" value="{{ $filters->period }}" wire:change="$set('period', $event.target.value)" class="form-control form-control-sm w-40 !text-body">
                    </div>
                @endif
                @if ($uses->contains(ReportFilter::Zone))
                    <div>
                        <label for="report-zone" class="mb-1 block text-caption font-semibold text-ink-strong">Zone</label>
                        <select id="report-zone" wire:change="$set('zone', $event.target.value)" class="form-control form-control-sm w-44 !text-body">
                            <option value="">All zones</option>
                            @foreach ($zones as $id => $name)
                                <option value="{{ $id }}" wire:key="zone-{{ $id }}" @selected($filters->zoneId === $id)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                @if ($uses->contains(ReportFilter::AccountType))
                    <div>
                        <label for="report-account-type" class="mb-1 block text-caption font-semibold text-ink-strong">Account type</label>
                        <select id="report-account-type" wire:change="$set('accountType', $event.target.value)" class="form-control form-control-sm w-44 !text-body">
                            <option value="">All account types</option>
                            @foreach ($accountTypes as $id => $name)
                                <option value="{{ $id }}" wire:key="account-type-{{ $id }}" @selected($filters->accountTypeId === $id)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                @if ($uses->contains(ReportFilter::Cashier))
                    <div>
                        <label for="report-cashier" class="mb-1 block text-caption font-semibold text-ink-strong">Received by</label>
                        <select id="report-cashier" wire:change="$set('cashier', $event.target.value)" class="form-control form-control-sm w-44 !text-body">
                            <option value="">Everyone</option>
                            @foreach ($cashiers as $id => $name)
                                <option value="{{ $id }}" wire:key="cashier-{{ $id }}" @selected($filters->cashierId === $id)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                @if ($filtered)
                    <button type="button" wire:click="resetFilters" class="text-button h-[30px]">Reset</button>
                @endif
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <a href="{{ route($page->value.'.export', $query) }}" class="link-button inline-flex items-center gap-1.5">
                    <x-icon name="upload" :size="14" class="rotate-180" />
                    Export CSV
                </a>
                <a href="{{ route($page->value.'.print', $query) }}" target="_blank" rel="noopener" class="form-button form-button-sm min-w-28">Print / PDF</a>
            </div>
        </section>

        <div wire:loading.class="opacity-60" class="grid gap-5 transition-opacity">
            <div>
                <p class="text-body font-semibold text-ink-strong">{{ $report->subtitle() }}</p>
                @if ($report->filterLabel())
                    <p class="text-caption text-label">{{ $report->filterLabel() }}</p>
                @endif
            </div>

            @php($summary = $report->summary())
            @if ($summary !== [])
                <section aria-label="Summary" class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach ($summary as $figure)
                        <div class="flex min-w-0 flex-col gap-1 rounded-xl border border-table-line bg-card p-4 text-right shadow-card">
                            <p class="mb-1 text-caption font-semibold tracking-wide text-label uppercase">{{ $figure['label'] }}</p>
                            <p class="truncate text-heading font-bold text-ink-strong" title="{{ $figure['value'] }}"><x-figure :value="$figure['value']" decimals="text-body" /></p>
                        </div>
                    @endforeach
                </section>
            @endif

            @foreach ($tables as $shown)
                @include('livewire.reports.partials.table', [
                    'table' => $shown['table'],
                    'tableId' => 'report-table-'.$loop->index,
                    'paginator' => $shown['paginator'],
                    'rowsPerPage' => $shown['rowsPerPage'],
                    'sort' => $shown['sort'],
                ])
            @endforeach

            @if ($report->note())
                <p class="text-caption text-label">{{ $report->note() }}</p>
            @endif
        </div>
    </div>
</x-page>
