@use('App\Support\Format')

<x-page title="Generate Bills">
    <div class="max-w-[1100px]">
        <x-flash class="mb-4" />

        <a href="{{ route('invoices') }}" class="link-button">← Back to Invoices</a>

        <ol class="mt-3 grid gap-2 rounded-xs bg-panel px-4 py-3 text-[13px] text-ink-soft sm:grid-cols-3">
            <li><span class="font-semibold text-ink-strong">1.</span> Choose the billing month (and a cluster to work through one route at a time).</li>
            <li><span class="font-semibold text-ink-strong">2.</span> Type each customer's present reading, or tick <em>Defective meter</em>. The bill is worked out as you go.</li>
            <li><span class="font-semibold text-ink-strong">3.</span> Click <em>Generate Bills</em>. Rows left blank are skipped; you can come back for them.</li>
        </ol>

        <div class="mt-4 grid grid-cols-1 gap-x-5 gap-y-3 sm:grid-cols-2 lg:grid-cols-5">
            <div>
                <label for="bill-period" class="mb-1 block text-xs font-semibold text-ink-strong">Billing month</label>
                <input id="bill-period" type="month" max="{{ substr($today, 0, 7) }}" wire:model.live="period" class="form-control !h-[34px] !text-[13px]" @error('period') aria-invalid="true" @enderror>
                @error('period') <p class="form-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="bill-read-on" class="mb-1 block text-xs font-semibold text-ink-strong">Reading date</label>
                <input id="bill-read-on" type="date" max="{{ $today }}" wire:model="readOn" class="form-control !h-[34px] !text-[13px]" @error('readOn') aria-invalid="true" @enderror>
                @error('readOn') <p class="form-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="bill-invoice-date" class="mb-1 block text-xs font-semibold text-ink-strong">Invoice date</label>
                <input id="bill-invoice-date" type="date" max="{{ $today }}" wire:model.live="invoiceDate" class="form-control !h-[34px] !text-[13px]" @error('invoiceDate') aria-invalid="true" @enderror>
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
                <x-search-box label="Search customers to bill" :width="200" wire:model.live.debounce.300ms="search" />
            </div>
        </div>

        <div class="table-scroller mt-4">
            <table class="data-table [&_th]:text-ink-strong [&_tbody_tr]:h-11">
                <thead>
                    <tr>
                        <th scope="col">Account No</th>
                        <th scope="col">Customer</th>
                        <th scope="col">Meter No</th>
                        <th scope="col">Rate</th>
                        <th scope="col" class="cell-right">Previous</th>
                        <th scope="col" class="w-32">Present Reading</th>
                        <th scope="col" class="cell-center">Defective</th>
                        <th scope="col" class="cell-right">Cu.m.</th>
                        <th scope="col" class="cell-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($customers as $customer)
                        @php
                            $row = $rows[$customer->id];
                            $preview = $previews[$customer->id] ?? null;
                            $locked = $row['billed'] !== null || $row['nothingToBill'] || $periodLabel === null || ! $access['add'];
                        @endphp
                        <tr wire:key="bill-row-{{ $customer->id }}-{{ $period }}">
                            <td class="whitespace-nowrap">{{ $customer->account_number }}</td>
                            <td>
                                {{ $customer->first_name }} {{ $customer->last_name }}
                                <span class="block text-[11px] {{ $customer->status->colorClass() }}">{{ $customer->status->label() }}</span>
                            </td>
                            <td class="whitespace-nowrap">{{ $customer->meter_number }}</td>
                            <td>{{ $customer->rateType?->name ?? '—' }}</td>
                            <td class="cell-right">{{ $row['previous'] ?? '—' }}</td>
                            @if ($row['billed'])
                                <td colspan="4" class="text-[13px] text-success">
                                    Billed — invoice {{ $row['billed']->invoice_number }} ({{ Format::amount($row['billed']->total_amount) }})
                                </td>
                            @elseif ($row['nothingToBill'])
                                <td colspan="4" class="text-[13px] text-placeholder">Disconnected — final reading already billed</td>
                            @else
                                <td>
                                    <input
                                        type="text"
                                        inputmode="decimal"
                                        maxlength="13"
                                        aria-label="Present reading for {{ $customer->account_number }}"
                                        wire:model.blur="entries.{{ $customer->id }}.present"
                                        @disabled($locked || ($entries[$customer->id]['defective'] ?? false))
                                        placeholder="{{ ($entries[$customer->id]['defective'] ?? false) ? 'Estimated' : '0' }}"
                                        class="form-control !h-[30px] text-right !text-[13px]"
                                        @if (isset($preview['error'])) aria-invalid="true" @endif
                                    >
                                </td>
                                <td class="cell-center">
                                    <input
                                        type="checkbox"
                                        aria-label="Defective meter for {{ $customer->account_number }}"
                                        wire:model.live="entries.{{ $customer->id }}.defective"
                                        @disabled($locked)
                                        class="size-4 accent-accent"
                                    >
                                </td>
                                @if (isset($preview['error']))
                                    <td colspan="2" class="text-[12px] text-danger">{{ $preview['error'] }}</td>
                                @elseif ($preview)
                                    <td class="cell-right">{{ $preview['consumption'] }}</td>
                                    <td class="cell-right font-semibold">
                                        {{ Format::amount($preview['total']) }}
                                        @if ($preview['note'])
                                            <span class="block text-[11px] font-normal text-label">{{ $preview['note'] }}</span>
                                        @endif
                                    </td>
                                @else
                                    <td class="cell-right text-placeholder">—</td>
                                    <td class="cell-right text-placeholder">—</td>
                                @endif
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="cell-empty py-6 text-center">
                                No customers to bill. Customers appear here once they are Connected with a meter (complete a New Installation work order).
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-pagination :paginator="$customers" />

        @if ($access['add'])
            <div class="sticky bottom-0 mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-line bg-card py-3">
                <p class="text-[13px] text-ink-soft">
                    <span class="font-semibold text-ink-strong">{{ $readyCount }}</span> bill{{ $readyCount === 1 ? '' : 's' }} ready
                    @if ($periodLabel) for {{ $periodLabel }} @endif
                    · Total <span class="font-semibold text-ink-strong">{{ Format::amount($readyTotal) }}</span>
                </p>
                <button type="button" class="form-button min-w-[160px]" wire:click="generate" wire:loading.attr="disabled" wire:target="generate" @disabled($readyCount === 0)>
                    <span wire:loading.remove wire:target="generate">Generate Bills</span>
                    <span wire:loading wire:target="generate">Generating…</span>
                </button>
            </div>
        @endif
    </div>
</x-page>
