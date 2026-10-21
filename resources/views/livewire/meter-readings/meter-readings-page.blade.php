@use('App\Enums\CustomerStatus')
@use('App\Enums\MeterReadingStatus')
@use('App\Enums\ReadingCode')
@use('App\Livewire\MeterReadings\MeterReadingsPage')
@use('App\Support\Format')

@php
    $counts = ['all' => $stats['total'], 'unread' => $stats['unread'], 'review' => $stats['review'], 'ready' => $stats['ready'], 'billed' => $stats['billed']];
    $canEnter = $periodLabel !== null && ($access['add'] || $access['edit']);
@endphp

<x-page title="Meter Readings" guide="meter-readings">
    <div>
        <x-flash class="mb-4" />

        {{-- Month, reading date and route --}}
        <div class="flex flex-wrap items-end justify-between gap-3">
            <p class="text-[13px] text-label">
                @if ($periodLabel)
                    {{ $periodLabel }} · <span class="font-semibold text-ink-strong">{{ Format::integer($stats['read']) }}</span> of {{ Format::integer($stats['total']) }} meters read
                @else
                    Choose a billing month.
                @endif
            </p>
            <div class="flex flex-wrap items-end gap-2">
                <div>
                    <label for="mr-period" class="mb-1 block text-xs font-semibold text-ink-strong">Billing month</label>
                    <input id="mr-period" type="month" max="{{ substr($today, 0, 7) }}" wire:model.live="period" class="form-control form-control-sm w-40 !text-[13px]">
                </div>
                <div>
                    <label for="mr-read-on" class="mb-1 block text-xs font-semibold text-ink-strong">Read on</label>
                    <input id="mr-read-on" type="date" max="{{ $today }}" wire:model.blur="readOn" class="form-control form-control-sm w-40 !text-[13px]">
                </div>
                <div>
                    <label for="mr-cluster" class="mb-1 block text-xs font-semibold text-ink-strong">Cluster</label>
                    <select id="mr-cluster" wire:model.live="clusterId" class="form-control form-control-sm w-44 !text-[13px]">
                        <option value="">All clusters</option>
                        @foreach ($clusters as $id => $name)
                            <option value="{{ $id }}" wire:key="cluster-{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        {{-- Progress --}}
        <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-md bg-panel px-4 py-3">
                <p class="text-xs text-label">Read</p>
                <p class="mt-0.5 text-xl font-semibold text-ink-strong">{{ Format::integer($stats['read']) }}<span class="text-sm font-normal text-placeholder"> / {{ Format::integer($stats['total']) }}</span></p>
                <div class="mt-2 h-1 rounded-full bg-line" role="progressbar" aria-label="Meters read" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $stats['percent'] }}">
                    <div class="h-1 rounded-full bg-success" style="width: {{ $stats['percent'] }}%"></div>
                </div>
            </div>
            <button type="button" wire:click="$set('filter', 'review')" class="rounded-md bg-panel px-4 py-3 text-left hover:bg-row-selected">
                <p class="text-xs text-label">Needs review</p>
                <p @class(['mt-0.5 text-xl font-semibold', 'text-warning' => $stats['review'] > 0, 'text-ink-strong' => $stats['review'] === 0])>{{ Format::integer($stats['review']) }}</p>
                <p class="mt-1 text-[11px] text-label">{{ $stats['review'] > 0 ? 'Confirm or re-read these' : 'Nothing to check' }}</p>
            </button>
            <div class="rounded-md bg-panel px-4 py-3">
                <p class="text-xs text-label">Ready to bill</p>
                <p class="mt-0.5 text-xl font-semibold text-ink-strong">{{ Format::integer($stats['ready']) }}</p>
                @if ($stats['ready'] > 0 && auth()->user()->canAccessPage(\App\Enums\Page::Invoices, \App\Enums\Access::Add))
                    <a href="{{ route('invoices.generate', ['period' => $period, 'cluster' => $clusterId ?: null]) }}" class="mt-1 inline-block text-[11px] font-semibold text-accent underline">Generate bills →</a>
                @else
                    <p class="mt-1 text-[11px] text-label">{{ Format::integer($stats['billed']) }} billed</p>
                @endif
            </div>
            <div class="rounded-md bg-panel px-4 py-3">
                <p class="text-xs text-label">Total usage</p>
                <p class="mt-0.5 text-xl font-semibold text-ink-strong">{{ Format::quantity($stats['usage']) }}<span class="text-sm font-normal text-placeholder"> cu.m.</span></p>
            </div>
        </div>

        {{-- Filters --}}
        <div class="mt-5 flex flex-wrap items-center gap-2">
            @foreach (MeterReadingsPage::FILTERS as $value => $label)
                <button
                    type="button"
                    wire:click="$set('filter', '{{ $value }}')"
                    aria-pressed="{{ $filter === $value ? 'true' : 'false' }}"
                    @class([
                        'h-7 rounded-full border px-3 text-xs',
                        'border-accent bg-accent text-white' => $filter === $value,
                        'border-line text-ink-soft hover:border-line-hover' => $filter !== $value,
                    ])
                >
                    {{ $label }} <span @class(['ml-0.5', 'text-white/70' => $filter === $value, 'text-placeholder' => $filter !== $value])>{{ Format::integer($counts[$value]) }}</span>
                </button>
            @endforeach
            <div class="ml-auto">
                <x-search-box label="Search account, name or meter" :width="220" wire:model.live.debounce.300ms="search" />
            </div>
        </div>

        <div @class(['mt-3 grid gap-4', 'lg:grid-cols-[minmax(0,1fr)_340px]' => $panel])>
            {{-- Reading list --}}
            <div class="min-w-0 overflow-hidden rounded-md border border-table-line">
                <div class="table-scroller">
                    <table class="w-full text-[13px]">
                        <thead>
                            <tr class="border-b border-table-line text-left text-[11px] text-label">
                                <th scope="col" class="px-3 py-2 font-medium">Customer</th>
                                <th scope="col" class="px-3 py-2 text-right font-medium">Previous</th>
                                <th scope="col" class="w-36 px-3 py-2 text-right font-medium">Present</th>
                                <th scope="col" class="px-3 py-2 text-right font-medium">Cu.m.</th>
                                <th scope="col" class="w-10 px-3 py-2"><span class="sr-only">Status</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($customers as $customer)
                                @php
                                    $row = $rows[$customer->id];
                                    $reading = $row['reading'];
                                    $billed = $reading?->status === MeterReadingStatus::Billed;
                                    $error = $rowErrors[$customer->id] ?? null;
                                    $isSelected = (string) $customer->id === $selected;
                                @endphp
                                <tr
                                    wire:key="reading-{{ $customer->id }}-{{ $period }}-{{ $reading?->id }}-{{ $reading?->updated_at?->getTimestamp() }}"
                                    @class(['border-b border-table-line last:border-b-0', 'bg-row-selected' => $isSelected, 'hover:bg-row-hover' => ! $isSelected])
                                >
                                    <td class="px-3 py-2">
                                        <button type="button" wire:click="select({{ $customer->id }})" class="text-left">
                                            <span class="block font-medium text-ink-strong hover:underline">{{ $customer->first_name }} {{ $customer->last_name }}</span>
                                            <span class="block text-[11px] text-label">
                                                {{ $customer->account_number }} · {{ $customer->meter_number }}
                                                @if ($customer->status === CustomerStatus::Disconnected)
                                                    · <span class="text-danger">Disconnected</span>
                                                @endif
                                            </span>
                                        </button>
                                    </td>
                                    <td class="px-3 py-2 text-right text-ink-soft tabular-nums">{{ $row['previous'] === null ? '—' : Format::quantity($row['previous']) }}</td>
                                    <td class="px-3 py-2 text-right">
                                        @if ($reading?->present_reading !== null)
                                            <span class="tabular-nums {{ $billed ? 'text-ink-soft' : 'font-medium text-ink-strong' }}">{{ Format::quantity($reading->present_reading) }}</span>
                                        @elseif ($reading)
                                            <span class="rounded-full bg-warning-bg px-2 py-0.5 text-[11px] text-warning">{{ $reading->reading_code->label() }}</span>
                                        @elseif ($canEnter && $access['add'])
                                            <button
                                                type="button"
                                                wire:click="select({{ $customer->id }})"
                                                class="inline-flex h-7 items-center rounded-full border border-dashed border-line-hover px-3 text-xs text-ink-soft hover:border-accent hover:text-ink-strong"
                                                aria-label="Enter the present reading for {{ $customer->account_number }}"
                                                @if ($error) aria-describedby="reading-error-{{ $customer->id }}" @endif
                                            >
                                                Enter reading
                                            </button>
                                        @else
                                            <span class="text-placeholder">—</span>
                                        @endif
                                        @if ($row['finalReading'] !== null && $reading === null)
                                            <span class="mt-0.5 block text-[11px] text-label">Final reading {{ Format::quantity($row['finalReading']) }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums">
                                        @if ($reading)
                                            <span class="font-semibold text-ink-strong">{{ Format::quantity($reading->usage()) }}</span>
                                            @if ($reading->reading_code->isEstimated())
                                                <span class="text-[11px] text-label">est.</span>
                                            @endif
                                        @else
                                            <span class="text-placeholder">—</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-center">
                                        <button type="button" wire:click="select({{ $customer->id }})" class="inline-flex size-6 items-center justify-center rounded-full" title="{{ $reading?->status->label() ?? 'Not read yet' }}">
                                            <span class="sr-only">{{ $reading?->status->label() ?? 'Not read yet' }} — open details</span>
                                            @if ($reading?->status === MeterReadingStatus::Review)
                                                <x-icon name="warning" :size="16" class="text-warning" />
                                            @elseif ($reading?->status === MeterReadingStatus::Ready)
                                                <x-icon name="check" :size="16" class="text-success" />
                                            @elseif ($billed)
                                                <x-icon name="receipt" :size="16" class="text-label" />
                                            @else
                                                <span class="size-2 rounded-full border border-line-hover" aria-hidden="true"></span>
                                            @endif
                                        </button>
                                    </td>
                                </tr>
                                @if ($error)
                                    <tr wire:key="reading-error-row-{{ $customer->id }}" class="border-b border-table-line bg-danger-bg">
                                        <td colspan="5" class="px-3 py-1.5 text-[12px] text-danger" id="reading-error-{{ $customer->id }}" role="alert">
                                            {{ $error }}
                                            <button type="button" class="ml-1 font-semibold underline" wire:click="select({{ $customer->id }})">Options</button>
                                        </td>
                                    </tr>
                                @endif
                            @empty
                                <tr>
                                    <td colspan="5" class="px-3 py-10 text-center text-[13px] text-label">
                                        @if ($stats['total'] === 0)
                                            No meters to read. Customers appear here once they are Connected with a meter (complete a New Installation work order).
                                        @else
                                            No meters match. Try another filter or search.
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($canEnter && $customers->isNotEmpty())
                    <p class="border-t border-table-line px-3 py-2 text-[11px] text-label">Click <em>Enter reading</em> (or a customer) to record the meter. Check the usage shown before saving.</p>
                @endif
            </div>

            {{-- Details of the selected meter --}}
            @if ($panel)
                @include('livewire.meter-readings.partials.panel')
            @endif
        </div>

        <x-pagination :paginator="$customers" />
    </div>
</x-page>
