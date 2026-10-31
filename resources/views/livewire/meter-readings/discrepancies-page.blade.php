@use('App\Enums\DiscrepancySeverity')
@use('App\Enums\SyncResolution')
@use('App\Livewire\MeterReadings\DiscrepanciesPage')
@use('App\Support\Format')
@use('App\Support\Money')

<x-page title="Discrepancies" guide="discrepancies">
    <div>
        <x-flash class="mb-4" />

        <a href="{{ route('meter-readings') }}" class="link-button">← Back to Meter Readings</a>

        <p class="mt-3 max-w-3xl text-body text-label">
            Readings from the meter reader app that differ from the billing data now, e.g. read twice, a meter changed, or a bill printed before a rate change.
            <span class="font-semibold text-ink-strong">Held</span> readings weren't saved; their bill was already printed and handed to the customer.
        </p>

        <div class="mt-4 flex flex-wrap items-center gap-2">
            @foreach (DiscrepanciesPage::FILTERS as $value => $label)
                <button
                    type="button"
                    wire:key="filter-{{ $value }}"
                    wire:click="$set('filter', '{{ $value }}')"
                    aria-pressed="{{ $filter === $value ? 'true' : 'false' }}"
                    @class([
                        'h-7 rounded-full border px-3 text-caption',
                        'border-accent bg-accent text-white' => $filter === $value,
                        'border-line text-ink-soft hover:border-line-hover' => $filter !== $value,
                    ])
                >
                    {{ $label }}
                    @if ($value === 'held')
                        <span @class(['ml-0.5', 'text-white/70' => $filter === $value, 'text-placeholder' => $filter !== $value])>{{ Format::integer($heldCount) }}</span>
                    @endif
                </button>
            @endforeach
        </div>

        <div @class(['mt-3 grid gap-4', 'lg:grid-cols-[minmax(0,1fr)_420px]' => $selected])>
            <div class="min-w-0 overflow-hidden rounded-md border border-table-line">
                <div class="table-scroller">
                    <table class="w-full text-body">
                        <thead>
                            <tr class="border-b border-table-line text-left text-caption text-label">
                                <th scope="col" class="px-4 py-3 font-medium">Customer</th>
                                <th scope="col" class="px-4 py-3 font-medium">Reader</th>
                                <th scope="col" class="px-4 py-3 font-medium">Found</th>
                                <th scope="col" class="px-4 py-3 text-right font-medium">Printed</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($items as $item)
                                @php($isSelected = $selected?->id === $item->id)
                                <tr wire:key="item-{{ $item->id }}" @class(['border-b border-table-line last:border-b-0', 'bg-row-selected' => $isSelected, 'hover:bg-row-hover' => ! $isSelected])>
                                    <td class="px-4 py-3">
                                        <button type="button" wire:click="select({{ $item->id }})" class="text-left">
                                            <span class="block font-medium text-ink-strong hover:underline">{{ $item->customer?->fullName() ?? 'Deleted customer' }}</span>
                                            <span class="block text-caption text-label">Acct No: {{ $item->customer?->account_number ?? '—' }} · {{ $item->billing_period }}</span>
                                        </button>
                                    </td>
                                    <td class="px-4 py-3 text-ink-soft">
                                        {{ $item->device->user->name }}
                                        <span class="block text-caption text-label">{{ $item->device->name }}</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        @foreach ($item->discrepancies as $discrepancy)
                                            <span wire:key="tag-{{ $discrepancy->id }}" @class([
                                                'mb-1 mr-1 inline-block rounded-full px-2 py-0.5 text-caption',
                                                'bg-warning-bg text-warning' => $discrepancy->severity === DiscrepancySeverity::Blocking,
                                                'bg-panel text-label' => $discrepancy->severity === DiscrepancySeverity::Info,
                                            ])>{{ $discrepancy->check->label() }}</span>
                                        @endforeach
                                        @if ($item->resolution)
                                            <span class="block text-caption text-label">{{ $item->resolution->label() }} by {{ $item->resolvedBy?->name }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums">
                                        @if (isset($item->payload['bill']))
                                            {{ Format::amount(Money::fromCents((int) $item->payload['bill']['total_cents'])) }}
                                            <span class="block text-caption text-label">No. {{ $item->payload['bill']['invoice_number'] }}</span>
                                            @if (($item->payload['bill']['reprint_count'] ?? 0) > 0)
                                                <span class="block text-caption font-semibold text-warning">Reprinted {{ Format::integer($item->payload['bill']['reprint_count']) }}×</span>
                                            @endif
                                        @else
                                            <span class="text-placeholder">Not printed</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-3 py-10 text-center text-body text-label">
                                        {{ $filter === 'held' ? 'Nothing is held. Every reading synced from the phones matched.' : 'Nothing to show.' }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <x-pagination :paginator="$items" />
            </div>

            @if ($selected)
                @php($bill = $selected->payload['bill'] ?? null)
                <aside class="rounded-md border border-table-line p-4" aria-label="Discrepancy details">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="text-body font-semibold text-ink-strong">{{ $selected->customer?->fullName() }}</h2>
                            <p class="text-caption text-label">
                                Read {{ Format::dateTime(\Illuminate\Support\Carbon::parse($selected->payload['read_at']), auth()->user()->company?->timezone) }}
                                by {{ $selected->device->user->name }} ({{ $selected->device->name }})
                            </p>
                        </div>
                        <button type="button" wire:click="closePanel" class="link-button text-caption">Close</button>
                    </div>

                    <dl class="mt-3 grid grid-cols-2 gap-2 text-body">
                        <div><dt class="text-caption text-label">Present reading</dt><dd class="tabular-nums text-ink-strong">{{ $selected->payload['present_reading'] ?? $selected->payload['reading_code'] }}</dd></div>
                        <div><dt class="text-caption text-label">Previous on the phone</dt><dd class="tabular-nums text-ink-strong">{{ Format::quantity($selected->payload['previous_reading']) }}</dd></div>
                    </dl>

                    <h3 class="mt-4 text-caption font-semibold text-ink-strong">What differs</h3>
                    <ul class="mt-1 space-y-2">
                        @foreach ($selected->discrepancies as $discrepancy)
                            <li wire:key="finding-{{ $discrepancy->id }}" class="rounded-xs bg-panel px-3 py-2 text-body">
                                <span class="font-medium text-ink-strong">{{ $discrepancy->check->label() }}</span>
                                @if ($discrepancy->severity === DiscrepancySeverity::Info)
                                    <span class="text-caption text-label">(info)</span>
                                @endif
                                <span class="block text-ink-soft">{{ $discrepancy->message }}</span>
                                @if ($discrepancy->device_value !== null || $discrepancy->server_value !== null)
                                    <span class="block text-caption text-label">Phone: {{ $discrepancy->device_value ?? '—' }} · Now: {{ $discrepancy->server_value ?? '—' }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    @if ($bill)
                        <h3 class="mt-4 text-caption font-semibold text-ink-strong">
                            Printed bill No. {{ $bill['invoice_number'] }}
                            @if (($bill['reprint_count'] ?? 0) > 0)
                                <span class="font-normal text-warning">· reprinted {{ Format::integer($bill['reprint_count']) }}×</span>
                            @endif
                        </h3>
                        <table class="mt-1 w-full text-body">
                            <tbody>
                                @foreach ($bill['lines'] as $index => $line)
                                    <tr wire:key="line-{{ $index }}" class="border-b border-table-line last:border-b-0">
                                        <td class="py-1.5 text-ink-soft">{{ $line['description'] }}</td>
                                        <td class="py-1.5 text-right tabular-nums">{{ Format::amount(Money::fromCents((int) $line['cents'])) }}</td>
                                    </tr>
                                @endforeach
                                <tr>
                                    <td class="py-1.5 font-semibold text-ink-strong">Current charges</td>
                                    <td class="py-1.5 text-right font-semibold tabular-nums">{{ Format::amount(Money::fromCents((int) $bill['total_cents'])) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    @endif

                    @if ($selected->isHeld() && $this->access['edit'])
                        <div class="mt-4">
                            <label for="discrepancy-note" class="mb-1 block text-caption font-semibold text-ink-strong">Note<x-required-mark /></label>
                            <textarea id="discrepancy-note" rows="2" wire:model="note" class="form-control !text-body" @error('note') aria-invalid="true" aria-describedby="discrepancy-note-error" @enderror></textarea>
                            @error('note') <p id="discrepancy-note-error" class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @if ($bill)
                                <button type="button" wire:click="resolve('{{ SyncResolution::AcceptedAsPrinted->value }}')" class="form-button form-button-sm">Accept as printed</button>
                                <button type="button" wire:click="resolve('{{ SyncResolution::Rebilled->value }}')" class="text-button">Re-bill with current figures</button>
                            @else
                                <button type="button" wire:click="resolve('{{ SyncResolution::Rebilled->value }}')" class="form-button form-button-sm">Save the reading</button>
                            @endif
                            <button type="button" wire:click="resolve('{{ SyncResolution::Rejected->value }}')" wire:confirm="Reject this reading? Nothing is saved and the meter must be read again." class="link-button text-danger">Reject reading</button>
                        </div>
                        <p class="mt-2 text-caption text-label">
                            <span class="font-semibold">Accept</span> issues the invoice exactly as printed. <span class="font-semibold">Re-bill</span> issues it with today's figures under the same number, marked Corrected, so deliver a corrected bill. <span class="font-semibold">Reject</span> saves nothing; read the meter again.
                        </p>
                    @elseif ($selected->resolution)
                        <p class="mt-4 text-body text-ink-soft">
                            {{ $selected->resolution->label() }} by {{ $selected->resolvedBy?->name }}: “{{ $selected->resolution_note }}”
                            @if ($selected->invoice)
                                · Invoice {{ $selected->invoice->invoice_number }}
                            @endif
                        </p>
                    @endif
                </aside>
            @endif
        </div>
    </div>
</x-page>
