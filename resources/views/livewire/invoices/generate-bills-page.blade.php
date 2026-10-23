@use('App\Support\Format')

<x-page title="Generate Bills" guide="generate-bills">
    <div class="max-w-[1280px]">
        <x-flash class="mb-4" />

        <a href="{{ route('invoices') }}" class="link-button">← Back to Invoices</a>

        <ol class="mt-3 grid gap-2 rounded-xs bg-panel px-4 py-3 text-[13px] text-ink-soft sm:grid-cols-3">
            <li><span class="font-semibold text-ink-strong">1.</span> Enter the month's readings on the <em>Meter Readings</em> page.</li>
            <li><span class="font-semibold text-ink-strong">2.</span> Confirm or re-read the readings that need review there. Only Ready readings are billed.</li>
            <li><span class="font-semibold text-ink-strong">3.</span> Choose the invoice date and click <em>Generate Bills</em>. Readings entered later can be billed in another run.</li>
        </ol>

        <div class="mt-4 grid grid-cols-1 gap-x-5 gap-y-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <label for="bill-period" class="mb-1 block text-xs font-semibold text-ink-strong">Billing month<x-required-mark /></label>
                <input id="bill-period" aria-required="true" type="month" max="{{ substr($today, 0, 7) }}" wire:model.live="period" class="form-control !h-[34px] !text-[13px]" @error('period') aria-invalid="true" @enderror>
                @error('period') <p class="form-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="bill-invoice-date" class="mb-1 block text-xs font-semibold text-ink-strong">Invoice date<x-required-mark /></label>
                <input id="bill-invoice-date" aria-required="true" type="date" max="{{ $today }}" wire:model.live="invoiceDate" class="form-control !h-[34px] !text-[13px]" @error('invoiceDate') aria-invalid="true" @enderror>
                @error('invoiceDate')
                    <p class="form-error" role="alert">{{ $message }}</p>
                @else
                    <p class="mt-1 text-xs text-label">Due {{ $dueDays }} day{{ $dueDays === 1 ? '' : 's' }} after (set in Operation Setup).</p>
                @enderror
            </div>
            <div>
                <label for="bill-cluster" class="mb-1 block text-xs font-semibold text-ink-strong">Cluster</label>
                <select id="bill-cluster" wire:model.live="clusterId" class="form-control !h-[34px] !text-[13px]">
                    <option value="">All clusters</option>
                    @foreach ($clusters as $id => $name)
                        <option value="{{ $id }}" wire:key="cluster-{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end">
                <x-search-box label="Search readings to bill" :width="200" wire:model.live.debounce.300ms="search" />
            </div>
        </div>

        @if ($periodLabel && ($counts['review'] > 0 || $counts['toRead'] > 0))
            <x-alert tone="info" class="mt-4">
                {{ $periodLabel }}:
                @if ($counts['toRead'] > 0) {{ Format::integer($counts['toRead']) }} meter{{ $counts['toRead'] === 1 ? '' : 's' }} not read yet. @endif
                @if ($counts['review'] > 0) {{ Format::integer($counts['review']) }} reading{{ $counts['review'] === 1 ? '' : 's' }} need review and won't be billed until confirmed. @endif
                @if ($canRead)
                    <a href="{{ route('meter-readings', ['period' => $period, 'cluster' => $clusterId ?: null, 'show' => $counts['review'] > 0 ? 'review' : 'unread']) }}">Open Meter Readings</a>
                @endif
            </x-alert>
        @endif

        <div class="table-scroller mt-4">
            {{-- On narrow screens the Rate (2nd) and Previous (3rd) columns are hidden. --}}
            <table class="data-table max-md:[&_tr>*:nth-child(2)]:hidden max-md:[&_tr>*:nth-child(3)]:hidden">
                <thead>
                    <tr>
                        <th scope="col">Customer</th>
                        <th scope="col">Rate</th>
                        <th scope="col" class="cell-right">Previous</th>
                        <th scope="col" class="cell-right">Present</th>
                        <th scope="col" class="cell-right">Cu.m.</th>
                        <th scope="col" class="cell-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($readings as $reading)
                        @php
                            $customer = $reading->customer;
                            $preview = $previews[$reading->id] ?? null;
                            $failure = $failures[$reading->id] ?? null;
                        @endphp
                        <tr wire:key="bill-row-{{ $reading->id }}">
                            <td>
                                <span class="block font-medium text-ink-strong">{{ $customer->first_name }} {{ $customer->last_name }}</span>
                                <span class="mt-0.5 block text-xs whitespace-nowrap text-label tabular-nums">Acct No: {{ $customer->account_number }} · Meter {{ $reading->meter_number }}</span>
                            </td>
                            <td>{{ $customer->rateType?->name ?? '—' }}</td>
                            <td class="cell-right">{{ Format::quantity($reading->previous_reading) }}</td>
                            <td class="cell-right">{{ $reading->present_reading === null ? $reading->reading_code->label() : Format::quantity($reading->present_reading) }}</td>
                            <td class="cell-right">
                                {{ Format::quantity($reading->usage()) }}
                                @if ($reading->reading_code->isEstimated()) <span class="text-[11px] text-label">est.</span> @endif
                            </td>
                            @if ($reading->isBilled())
                                <td class="cell-right text-[13px] text-success">
                                    Billed — invoice {{ $reading->invoice?->invoice_number }}
                                    <span class="block">{{ Format::amount($reading->invoice?->total_amount ?? 0) }}</span>
                                </td>
                            @elseif ($failure || isset($preview['error']))
                                <td class="text-[12px] text-danger">{{ $failure ?? $preview['error'] }}</td>
                            @else
                                <td class="cell-right font-semibold">
                                    {{ Format::amount($preview['total'] ?? 0) }}
                                    @if ($preview['note'] ?? '')
                                        <span class="block text-[11px] font-normal text-label">{{ $preview['note'] }}</span>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="cell-empty py-6 text-center">
                                No readings ready to bill for this month.
                                @if ($canRead)
                                    Enter them on the <a href="{{ route('meter-readings', ['period' => $period]) }}" class="font-semibold underline">Meter Readings</a> page.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-pagination :paginator="$readings" />

        @if ($access['add'])
            <div class="sticky bottom-0 mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-line bg-card py-3">
                <p class="text-[13px] text-ink-soft">
                    <span class="font-semibold text-ink-strong">{{ Format::integer($counts['ready']) }}</span> reading{{ $counts['ready'] === 1 ? '' : 's' }} ready to bill
                    @if ($periodLabel) for {{ $periodLabel }} @endif
                    · {{ Format::integer($counts['billed']) }} billed
                </p>
                <button type="button" class="form-button min-w-[160px]" wire:click="generate" wire:loading.attr="disabled" wire:target="generate" @disabled($counts['ready'] === 0)>
                    <span wire:loading.remove wire:target="generate">Generate {{ $counts['ready'] > 0 ? Format::integer($counts['ready']).' ' : '' }}Bill{{ $counts['ready'] === 1 ? '' : 's' }}</span>
                    <span wire:loading wire:target="generate">Generating…</span>
                </button>
            </div>
        @endif
    </div>
</x-page>
