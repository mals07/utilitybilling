@use('App\Support\DashboardMetrics')
@use('App\Support\Format')
@use('App\Support\Money')

@php
    $money = fn (int $cents): string => $currency.Format::amount(Money::fromCents($cents));
    $agingColors = ['#86b6ef', '#5598e7', '#2a78d6', '#1c5cab', '#104281'];
    $seesAnything = in_array(true, $sees, true);
@endphp

<x-page title="Dashboard">
    {{-- Company and month --}}
    <section aria-label="Company" class="mb-5 flex flex-col gap-4 rounded-xl bg-navy bg-[image:var(--gradient-ocean)] px-4 py-4 text-white shadow-card sm:flex-row sm:items-center sm:px-5">
        <div class="flex min-w-0 flex-1 items-center gap-3">
            <div class="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-white ring-2 ring-white/25">
                @if ($logoUrl = $company->assetUrl('logo'))
                    <img src="{{ $logoUrl }}" alt="{{ $company->company_name }} logo" class="size-full object-contain p-1">
                @else
                    <x-app-logo :size="30" />
                @endif
            </div>
            <div class="min-w-0">
                <h2 class="truncate text-heading font-bold">{{ $company->company_name }}</h2>
                <p class="truncate text-caption text-sky-100/90">{{ $company->location() }}</p>
            </div>
            @if ($canEditCompany)
                <a href="{{ route('company') }}" class="ml-1 inline-flex size-8 shrink-0 items-center justify-center rounded-xs text-white/80 transition hover:bg-white/15 hover:text-white focus-visible:outline-2 focus-visible:outline-white" title="Edit company profile" aria-label="Edit company profile">
                    <x-icon name="pencil" :size="16" />
                </a>
            @endif
        </div>

        @if ($seesAnything)
            <div class="flex items-center gap-2">
                <label for="dashboard-month" class="text-caption font-semibold text-sky-100">Month</label>
                <input id="dashboard-month" type="month" max="{{ $currentMonth }}" value="{{ $shownMonth }}" wire:change="$set('month', $event.target.value)" class="form-control !h-9 !w-44 !text-body !text-ink">
                @unless ($isCurrentMonth)
                    <button type="button" wire:click="$set('month', '')" class="rounded-xs px-2 py-1 text-caption font-semibold text-white underline-offset-2 hover:underline">This month</button>
                @endunless
            </div>
        @endif
    </section>

    @unless ($seesAnything)
        <div class="rounded-xl border border-table-line bg-card p-8 text-center shadow-card">
            <p class="text-heading font-semibold text-ink-strong">No performance figures to show</p>
            <p class="mt-1 text-body text-label">Your role doesn't include the pages these figures come from. Ask an administrator if you need access.</p>
        </div>
    @else
        <div class="grid gap-5" wire:loading.class="opacity-60" wire:target="month">
            {{-- Headline figures --}}
            <section aria-label="Headline figures for {{ $monthLabel }}" class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @if ($sees['invoices'])
                    @include('livewire.dashboard.stat', [
                        'label' => 'Billed in '.$monthLabel,
                        'value' => $money($revenue['billed']),
                        'change' => DashboardMetrics::change($revenue['billed'], $revenue['previousBilled']),
                        'previousLabel' => $previousMonthLabel,
                    ])
                @endif
                @if ($sees['payments'])
                    @include('livewire.dashboard.stat', [
                        'label' => 'Collected in '.$monthLabel,
                        'value' => $money($revenue['collected']),
                        'change' => DashboardMetrics::change($revenue['collected'], $revenue['previousCollected']),
                        'previousLabel' => $previousMonthLabel,
                    ])
                @endif
                @if ($sees['invoices'] && $sees['payments'])
                    @include('livewire.dashboard.stat', [
                        'label' => 'Collection efficiency',
                        'value' => $revenue['efficiency'] === null ? '—' : number_format($revenue['efficiency'], 1).'%',
                        'change' => $revenue['efficiency'] !== null && $revenue['previousEfficiency'] !== null ? round($revenue['efficiency'] - $revenue['previousEfficiency'], 1) : null,
                        'changeUnit' => ' pts',
                        'previousLabel' => $previousMonthLabel,
                        'note' => $revenue['efficiency'] === null ? 'Nothing was billed this month.' : 'Collected ÷ billed in the month.',
                    ])
                @endif
                @if ($sees['invoices'])
                    @include('livewire.dashboard.stat', [
                        'label' => 'Outstanding receivables',
                        'value' => $money($receivables['outstanding']),
                        'upIsGood' => false,
                        'note' => $money($receivables['overdue']).' overdue · '.Format::integer($receivables['accounts']).' '.Str::plural('account', $receivables['accounts']).' · as of today',
                    ])
                @endif
                @if ($sees['readings'])
                    @include('livewire.dashboard.stat', [
                        'label' => 'Water consumption',
                        'value' => Format::quantity($water['consumption']).' cu.m.',
                        'change' => DashboardMetrics::change($water['consumption'], $water['previousConsumption']),
                        'previousLabel' => $previousMonthLabel,
                        'note' => $water['averagePerConnection'] === null ? 'No confirmed readings yet.' : Format::quantity($water['averagePerConnection']).' cu.m. average per connection',
                    ])
                @endif
                @if ($sees['customers'])
                    @include('livewire.dashboard.stat', [
                        'label' => 'Active connections',
                        'value' => Format::integer($customers['connected']),
                        'note' => '+'.Format::integer($customers['newInstallations']).' new in '.$monthLabel.' · as of today',
                    ])
                @endif
            </section>

            {{-- Six-month performance --}}
            @if ($trendMeasures !== [])
                <section
                    aria-labelledby="trend-heading"
                    class="rounded-xl border border-table-line bg-card p-4 shadow-card sm:p-5"
                    wire:key="trend-{{ $shownMonth }}"
                    wire:ignore
                    x-data="trendChart({ months: @js($trendMonths), measures: @js($trendMeasures), currency: @js($currency) })"
                >
                    <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 id="trend-heading" class="text-heading font-semibold text-ink-strong">6-month performance</h2>
                            <p class="text-caption text-label">{{ $trendMonths[0] }} – {{ end($trendMonths) }}. The best and worst months are marked High and Low.</p>
                        </div>
                        <div class="tab-scroller max-w-full">
                            <div class="flex gap-1 rounded-xs border border-table-line bg-panel p-1" role="tablist" aria-label="Measure">
                                @foreach ($trendMeasures as $measure)
                                    <button
                                        type="button"
                                        role="tab"
                                        wire:key="measure-{{ $measure['key'] }}"
                                        x-on:click="choose(@js($measure['key']))"
                                        x-bind:aria-selected="selected === @js($measure['key'])"
                                        class="rounded-[6px] px-3 py-1.5 text-caption font-semibold whitespace-nowrap text-label transition hover:text-ink-strong aria-selected:bg-card aria-selected:text-accent aria-selected:shadow-sm"
                                    >{{ $measure['label'] }}</button>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <template x-if="measure && measure.series.length > 1">
                        <ul class="mb-2 flex flex-wrap gap-4 text-caption text-ink-soft" aria-label="Legend">
                            <template x-for="series in measure.series" :key="series.label">
                                <li class="flex items-center gap-1.5">
                                    <span class="inline-block h-0.5 w-4 rounded-full" :style="`background-color: ${series.color}`"></span>
                                    <span x-text="series.label"></span>
                                </li>
                            </template>
                        </ul>
                    </template>

                    <div class="relative h-72">
                        <canvas x-ref="canvas" role="img" :aria-label="measure ? `${measure.label} by month, ${@js($trendMonths[0])} to ${@js(end($trendMonths))}` : ''"></canvas>
                    </div>

                    <dl class="mt-4 grid grid-cols-1 gap-3 border-t border-table-line pt-4 sm:grid-cols-3">
                        <div>
                            <dt class="text-caption font-semibold text-label uppercase">High</dt>
                            <dd class="text-body font-semibold text-ink-strong" x-text="summary?.high"></dd>
                        </div>
                        <div>
                            <dt class="text-caption font-semibold text-label uppercase">Low</dt>
                            <dd class="text-body font-semibold text-ink-strong" x-text="summary?.low"></dd>
                        </div>
                        <div>
                            <dt class="text-caption font-semibold text-label uppercase">6-month average</dt>
                            <dd class="text-body font-semibold text-ink-strong" x-text="summary?.average"></dd>
                        </div>
                    </dl>
                </section>
            @endif

            {{-- Revenue and collections --}}
            @if ($sees['invoices'] || $sees['payments'])
                <div class="grid gap-5 lg:grid-cols-2">
                    @if ($sees['invoices'])
                        <section aria-labelledby="aging-heading" class="rounded-xl border border-table-line bg-card p-4 shadow-card sm:p-5">
                            <h2 id="aging-heading" class="text-heading font-semibold text-ink-strong">Receivables aging</h2>
                            <p class="mb-4 text-caption text-label">Unpaid balances by days past due, as of today.</p>
                            @include('livewire.dashboard.bar-list', [
                                'listKey' => 'aging',
                                'empty' => 'No unpaid balances.',
                                'rows' => array_map(fn (array $bucket, int $index): array => [
                                    'label' => $bucket['label'],
                                    'amount' => $bucket['cents'],
                                    'text' => $money($bucket['cents']),
                                    'color' => $agingColors[$index],
                                ], $aging, array_keys($aging)),
                            ])
                        </section>
                    @endif

                    @if ($sees['payments'])
                        <section aria-labelledby="methods-heading" class="rounded-xl border border-table-line bg-card p-4 shadow-card sm:p-5">
                            <h2 id="methods-heading" class="text-heading font-semibold text-ink-strong">Collections by payment method</h2>
                            <p class="mb-4 text-caption text-label">Money received in {{ $monthLabel }}.</p>
                            @php $collectedTotal = array_sum(array_column($collectionsByMethod, 'cents')); @endphp
                            @include('livewire.dashboard.bar-list', [
                                'listKey' => 'methods',
                                'empty' => 'No payments received in '.$monthLabel.'.',
                                'rows' => array_map(fn (array $method): array => [
                                    'label' => $method['label'].' ('.Format::integer($method['count']).')',
                                    'amount' => $method['cents'],
                                    'text' => $money($method['cents']).' · '.number_format($method['cents'] / max(1, $collectedTotal) * 100, 0).'%',
                                ], $collectionsByMethod),
                            ])
                        </section>
                    @endif

                    @if ($sees['invoices'])
                        <section aria-labelledby="account-type-heading" class="rounded-xl border border-table-line bg-card p-4 shadow-card sm:p-5">
                            <h2 id="account-type-heading" class="text-heading font-semibold text-ink-strong">Billed by account type</h2>
                            <p class="mb-4 text-caption text-label">Bills dated in {{ $monthLabel }}.</p>
                            @include('livewire.dashboard.bar-list', [
                                'listKey' => 'account-types',
                                'empty' => 'Nothing billed in '.$monthLabel.'.',
                                'rows' => array_map(fn (array $type): array => [
                                    'label' => $type['label'],
                                    'amount' => $type['cents'],
                                    'text' => $money($type['cents']),
                                ], $billedByAccountType),
                            ])
                        </section>

                        <section aria-labelledby="adjustments-heading" class="rounded-xl border border-table-line bg-card p-4 shadow-card sm:p-5">
                            <h2 id="adjustments-heading" class="text-heading font-semibold text-ink-strong">Bill adjustments</h2>
                            <p class="mb-4 text-caption text-label">Credit and debit memos issued in {{ $monthLabel }} (voided memos left out).</p>
                            <dl class="grid grid-cols-2 gap-3">
                                <div class="rounded-xs bg-panel p-3">
                                    <dt class="text-caption font-semibold text-label">Credits given</dt>
                                    <dd class="text-heading font-bold text-ink-strong">{{ $money($adjustments['credits']) }}</dd>
                                    <dd class="text-caption text-label">{{ Format::integer($adjustments['creditCount']) }} {{ Str::plural('memo', $adjustments['creditCount']) }}</dd>
                                </div>
                                <div class="rounded-xs bg-panel p-3">
                                    <dt class="text-caption font-semibold text-label">Debits added</dt>
                                    <dd class="text-heading font-bold text-ink-strong">{{ $money($adjustments['debits']) }}</dd>
                                    <dd class="text-caption text-label">{{ Format::integer($adjustments['debitCount']) }} {{ Str::plural('memo', $adjustments['debitCount']) }}</dd>
                                </div>
                            </dl>
                        </section>
                    @endif
                </div>
            @endif

            {{-- Top delinquent accounts --}}
            @if ($sees['invoices'])
                <section aria-labelledby="delinquent-heading" class="rounded-xl border border-table-line bg-card p-4 shadow-card sm:p-5">
                    <h2 id="delinquent-heading" class="text-heading font-semibold text-ink-strong">Top {{ DashboardMetrics::TOP_DELINQUENT }} delinquent accounts</h2>
                    <p class="mb-3 text-caption text-label">The largest balances past due, as of today.</p>
                    @if ($topDelinquent === [])
                        <p class="py-6 text-center text-body text-muted">No accounts are past due.</p>
                    @else
                        <div class="table-scroller">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Account</th>
                                        <th>Customer</th>
                                        <th class="cell-right">Overdue bills</th>
                                        <th>Oldest due date</th>
                                        <th class="cell-right">Amount past due</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($topDelinquent as $account)
                                        <tr wire:key="delinquent-{{ $account['customerId'] }}">
                                            <td>
                                                @if ($sees['customers'])
                                                    <a href="{{ route('customers', ['customer' => $account['customerId']]) }}" class="link-button" wire:navigate>{{ $account['accountNumber'] }}</a>
                                                @else
                                                    <a href="{{ route('invoices', ['q' => $account['accountNumber']]) }}" class="link-button" wire:navigate>{{ $account['accountNumber'] }}</a>
                                                @endif
                                            </td>
                                            <td>{{ $account['name'] }}</td>
                                            <td class="cell-right">{{ Format::integer($account['bills']) }}</td>
                                            <td>{{ $account['oldestDue'] }}</td>
                                            <td class="cell-right font-semibold">{{ $money($account['cents']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            @endif

            {{-- Customers and meter readings --}}
            @if ($sees['customers'] || $sees['readings'])
                <div class="grid gap-5 lg:grid-cols-2">
                    @if ($sees['customers'])
                        <section aria-labelledby="customers-heading" class="rounded-xl border border-table-line bg-card p-4 shadow-card sm:p-5">
                            <h2 id="customers-heading" class="text-heading font-semibold text-ink-strong">Customers</h2>
                            <p class="mb-4 text-caption text-label">Status as of today; connection work completed in {{ $monthLabel }}.</p>
                            <dl class="grid grid-cols-3 gap-3">
                                @foreach (['Connected' => $customers['connected'], 'Disconnected' => $customers['disconnected'], 'Pending' => $customers['pending']] as $label => $count)
                                    <div class="rounded-xs bg-panel p-3" wire:key="customer-status-{{ $loop->index }}">
                                        <dt class="text-caption font-semibold text-label">{{ $label }}</dt>
                                        <dd class="text-heading font-bold text-ink-strong">{{ Format::integer($count) }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                            <dl class="mt-3 grid grid-cols-3 gap-3 border-t border-table-line pt-3">
                                @foreach (['New installations' => $customers['newInstallations'], 'Disconnections' => $customers['disconnections'], 'Reconnections' => $customers['reconnections']] as $label => $count)
                                    <div wire:key="customer-movement-{{ $loop->index }}">
                                        <dt class="text-caption text-label">{{ $label }}</dt>
                                        <dd class="text-body font-semibold text-ink-strong">{{ Format::integer($count) }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </section>
                    @endif

                    @if ($sees['readings'])
                        <section aria-labelledby="readings-heading" class="rounded-xl border border-table-line bg-card p-4 shadow-card sm:p-5">
                            <h2 id="readings-heading" class="text-heading font-semibold text-ink-strong">Meter reading progress</h2>
                            <p class="mb-4 text-caption text-label">Readings for the {{ $monthLabel }} billing period, out of {{ Format::integer($readingProgress['expected']) }} connected {{ Str::plural('meter', $readingProgress['expected']) }}.</p>
                            @php
                                $readingSteps = [
                                    ['label' => 'Billed', 'count' => $readingProgress['billed'], 'color' => '#0369a1'],
                                    ['label' => 'Ready to bill', 'count' => $readingProgress['ready'], 'color' => '#06b6d4'],
                                    ['label' => 'Needs review', 'count' => $readingProgress['review'], 'color' => '#eb6834'],
                                    ['label' => 'Not yet read', 'count' => $readingProgress['notRead'], 'color' => '#d3dee8'],
                                ];
                                $readingTotal = max(1, array_sum(array_column($readingSteps, 'count')));
                            @endphp
                            <div class="flex h-3 gap-0.5 overflow-hidden rounded-full bg-panel" aria-hidden="true">
                                @foreach ($readingSteps as $step)
                                    @if ($step['count'] > 0)
                                        <div wire:key="reading-bar-{{ $loop->index }}" class="h-full" style="width: {{ $step['count'] / $readingTotal * 100 }}%; background-color: {{ $step['color'] }}" title="{{ $step['label'] }}: {{ $step['count'] }}"></div>
                                    @endif
                                @endforeach
                            </div>
                            <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 sm:grid-cols-4">
                                @foreach ($readingSteps as $step)
                                    <div wire:key="reading-step-{{ $loop->index }}">
                                        <dt class="flex items-center gap-1.5 text-caption text-label">
                                            <span class="inline-block size-2 rounded-full" style="background-color: {{ $step['color'] }}" aria-hidden="true"></span>
                                            {{ $step['label'] }}
                                        </dt>
                                        <dd class="text-body font-semibold text-ink-strong">{{ Format::integer($step['count']) }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                            <p class="mt-3 border-t border-table-line pt-3 text-caption text-label">
                                Problem readings: <span class="font-semibold text-ink-strong">{{ Format::integer($readingProgress['noAccess']) }}</span> no access ·
                                <span class="font-semibold text-ink-strong">{{ Format::integer($readingProgress['defective']) }}</span> defective meter
                            </p>
                        </section>
                    @endif
                </div>
            @endif

            {{-- Operations --}}
            @if ($sees['workOrders'] || $sees['disconnections'])
                <div class="grid gap-5 lg:grid-cols-2">
                    @if ($sees['workOrders'])
                        <section aria-labelledby="work-orders-heading" class="rounded-xl border border-table-line bg-card p-4 shadow-card sm:p-5">
                            <h2 id="work-orders-heading" class="text-heading font-semibold text-ink-strong">Work orders</h2>
                            <p class="mb-4 text-caption text-label">Open orders as of today; completed orders in {{ $monthLabel }}.</p>
                            <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <div class="rounded-xs bg-panel p-3">
                                    <dt class="text-caption font-semibold text-label">In progress</dt>
                                    <dd class="text-heading font-bold text-ink-strong">{{ Format::integer($workOrders['inProgress']) }}</dd>
                                </div>
                                <div class="rounded-xs bg-panel p-3">
                                    <dt class="text-caption font-semibold text-label">On hold</dt>
                                    <dd class="text-heading font-bold text-ink-strong">{{ Format::integer($workOrders['onHold']) }}</dd>
                                </div>
                                <div @class(['rounded-xs p-3', 'bg-danger-bg' => $workOrders['overdue'] > 0, 'bg-panel' => $workOrders['overdue'] === 0])>
                                    <dt class="text-caption font-semibold text-label" title="Open for more than {{ \App\Models\WorkOrder::OVERDUE_AFTER_DAYS }} days">Overdue</dt>
                                    <dd @class(['text-heading font-bold', 'text-danger' => $workOrders['overdue'] > 0, 'text-ink-strong' => $workOrders['overdue'] === 0])>{{ Format::integer($workOrders['overdue']) }}</dd>
                                </div>
                                <div class="rounded-xs bg-panel p-3">
                                    <dt class="text-caption font-semibold text-label">Completed</dt>
                                    <dd class="text-heading font-bold text-ink-strong">{{ Format::integer($workOrders['completed']) }}</dd>
                                </div>
                            </dl>
                            <p class="mt-3 text-caption text-label">
                                Average time to complete:
                                <span class="font-semibold text-ink-strong">{{ $workOrders['averageDays'] === null ? '—' : number_format($workOrders['averageDays'], 1).' '.Str::plural('day', $workOrders['averageDays']) }}</span>
                            </p>
                            <h3 class="mt-4 mb-3 border-t border-table-line pt-3 text-body font-semibold text-ink-strong">Open orders by type</h3>
                            @include('livewire.dashboard.bar-list', [
                                'listKey' => 'order-types',
                                'empty' => 'No open work orders.',
                                'rows' => array_map(fn (array $type): array => [
                                    'label' => $type['label'],
                                    'amount' => $type['count'],
                                    'text' => Format::integer($type['count']),
                                ], $workOrders['openByType']),
                            ])
                        </section>
                    @endif

                    @if ($sees['disconnections'])
                        <section aria-labelledby="disconnections-heading" class="rounded-xl border border-table-line bg-card p-4 shadow-card sm:p-5">
                            <h2 id="disconnections-heading" class="text-heading font-semibold text-ink-strong">Disconnections</h2>
                            <p class="mb-4 text-caption text-label">Notices running as of today; notices closed in {{ $monthLabel }}.</p>
                            <dl class="grid grid-cols-3 gap-3">
                                <div class="rounded-xs bg-panel p-3">
                                    <dt class="text-caption font-semibold text-label">Pending</dt>
                                    <dd class="text-heading font-bold text-ink-strong">{{ Format::integer($disconnections['pending']) }}</dd>
                                </div>
                                <div class="rounded-xs bg-panel p-3">
                                    <dt class="text-caption font-semibold text-label">Scheduled</dt>
                                    <dd class="text-heading font-bold text-ink-strong">{{ Format::integer($disconnections['scheduled']) }}</dd>
                                </div>
                                <div class="rounded-xs bg-panel p-3">
                                    <dt class="text-caption font-semibold text-label">Amount at risk</dt>
                                    <dd class="truncate text-heading font-bold text-ink-strong">{{ $money($disconnections['atRisk']) }}</dd>
                                </div>
                            </dl>
                            <dl class="mt-3 grid grid-cols-3 gap-3 border-t border-table-line pt-3">
                                <div>
                                    <dt class="text-caption text-label">Settled before cut-off</dt>
                                    <dd class="text-body font-semibold text-ink-strong">{{ Format::integer($disconnections['settled']) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-caption text-label">Disconnected</dt>
                                    <dd class="text-body font-semibold text-ink-strong">{{ Format::integer($disconnections['disconnected']) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-caption text-label">Settlement rate</dt>
                                    <dd class="text-body font-semibold text-ink-strong">{{ $disconnections['settledRate'] === null ? '—' : number_format($disconnections['settledRate'], 1).'%' }}</dd>
                                </div>
                            </dl>
                        </section>
                    @endif
                </div>
            @endif
        </div>
    @endunless
</x-page>
