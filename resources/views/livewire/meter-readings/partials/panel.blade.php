@use('App\Enums\MeterReadingStatus')
@use('App\Enums\ReadingCode')
@use('App\Support\Format')

@php
    $customer = $panel['customer'];
    $reading = $panel['reading'];
    $billed = $reading?->status === MeterReadingStatus::Billed;
    $rowError = $rowErrors[$customer->id] ?? null;
    $canRollOver = $customer->meterType?->dial_digits !== null;
@endphp

<aside aria-label="Meter details" class="order-first self-start rounded-xl border border-table-line bg-card shadow-card lg:sticky lg:top-24 lg:order-none" wire:key="panel-{{ $customer->id }}">
    <div class="flex items-start justify-between gap-2 border-b border-table-line px-4 py-3">
        <div class="min-w-0">
            <p class="truncate font-semibold text-ink-strong">{{ $customer->first_name }} {{ $customer->last_name }}</p>
            <p class="text-[11px] text-label">
                {{ $customer->account_number }} · {{ $customer->meter_number }}
                @if ($customer->clusterType) · {{ $customer->clusterType->name }} @endif
            </p>
            <p class="text-[11px] text-label">
                {{ $customer->rateType?->name ?? 'No rate' }}
                @if ($customer->meterType) · {{ $customer->meterType->name }}{{ $customer->meterType->dial_digits ? " ({$customer->meterType->dial_digits} dials)" : '' }} @endif
                @if ($customer->accountType?->is_tax_exempt) · <span class="font-semibold">Tax exempt</span> @endif
            </p>
        </div>
        <button type="button" class="icon-button" wire:click="closePanel" aria-label="Close details">
            <x-icon name="close" :size="16" />
        </button>
    </div>

    <div class="space-y-4 px-4 py-3 text-[13px]">
        {{-- Status and what to do about it --}}
        @if ($reading)
            <div class="flex items-center gap-2">
                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $reading->status->badgeClass() }}">{{ $reading->status->label() }}</span>
                @if ($reading->reading_code !== ReadingCode::Normal)
                    <span class="rounded-full bg-panel px-2 py-0.5 text-[11px] text-ink-soft">{{ $reading->reading_code->label() }}</span>
                @endif
            </div>

            @if ($reading->status === MeterReadingStatus::Review)
                <div class="rounded-md bg-warning-bg px-3 py-2 text-[12px] text-warning" role="status">
                    {{ $reading->review_reason }}
                    @if ($access['edit'])
                        <div class="mt-2 flex gap-2">
                            <button type="button" class="h-7 flex-1 rounded-xs bg-button text-xs font-semibold text-white hover:bg-button-hover" wire:click="confirm({{ $customer->id }})">Reading is correct</button>
                            <button type="button" class="h-7 flex-1 rounded-xs border border-line bg-card text-xs text-ink-soft hover:border-line-hover" wire:click="reread({{ $customer->id }})" wire:confirm="Clear this reading so the meter can be read again?">Re-read meter</button>
                        </div>
                    @endif
                </div>
            @elseif ($reading->confirmed_at && $reading->review_reason)
                <p class="text-[11px] text-label">Confirmed by {{ $reading->confirmedBy?->name ?? 'a user' }}: {{ $reading->review_reason }}</p>
            @endif
        @elseif ($rowError && $panel['preview'] === null)
            <div class="rounded-md bg-danger-bg px-3 py-2 text-[12px] text-danger" role="alert">{{ $rowError }}</div>
        @else
            <p class="text-label">Not read yet for this month.</p>
        @endif

        {{-- Enter or correct the reading: the usage it makes shows before it is saved. --}}
        @if (! $billed && $periodLabel !== null && ($reading ? $access['edit'] : $access['add']))
            @php($preview = $panel['preview'])
            <form wire:submit="saveSelected(true)" class="rounded-md border border-table-line bg-panel p-3" novalidate>
                <div class="flex items-baseline justify-between gap-2">
                    <label for="mr-present" class="text-[11px] font-semibold text-ink-strong">{{ $reading ? 'Correct the present reading' : 'Present reading' }}<x-required-mark /></label>
                    <span class="text-[11px] text-label">Previous {{ $panel['previous'] === null ? '—' : Format::quantity($panel['previous']) }}</span>
                </div>
                <input
                    id="mr-present"
                    type="text"
                    inputmode="decimal"
                    maxlength="13"
                    autocomplete="off"
                    wire:model.live.debounce.400ms="presentInput"
                    aria-required="true"
                    x-init="$nextTick(() => $el.focus())"
                    placeholder="{{ $reading?->present_reading !== null ? Format::quantity($reading->present_reading) : '0' }}"
                    class="form-control mt-1 !h-11 text-right !text-lg font-semibold tabular-nums"
                    @if ($preview['error'] ?? false) aria-invalid="true" @endif
                    aria-describedby="mr-present-preview"
                >
                @error('presentInput') <p class="form-error" role="alert">{{ $message }}</p> @enderror

                <div id="mr-present-preview" class="mt-2 min-h-5 text-[12px]" aria-live="polite">
                    @if ($preview === null)
                        <span class="text-label">Type the number on the meter. Nothing is saved until you click Save.</span>
                    @elseif ($preview['error'])
                        <span class="text-danger">{{ $preview['error'] }}</span>
                    @else
                        <p class="text-ink-strong">Usage <span class="font-semibold tabular-nums">{{ Format::quantity($preview['usage']) }} cu.m.</span>
                            @if ($preview['deducted'] > 0) <span class="text-label">(after {{ Format::quantity($preview['deducted']) }} cu.m. already estimated)</span> @endif
                        </p>
                        @if ($preview['flags'] !== [])
                            <p class="mt-1 text-warning">Will need review: {{ implode(' ', $preview['flags']) }}</p>
                        @endif
                    @endif
                </div>

                <div class="mt-2 flex gap-2">
                    <button type="button" wire:click="saveSelected(false)" wire:loading.attr="disabled" wire:target="saveSelected" class="h-8 flex-1 rounded-xs border border-line bg-card text-xs text-ink-soft hover:border-line-hover" @disabled($preview === null || $preview['error'])>Save</button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="saveSelected" class="h-8 flex-1 rounded-xs bg-button text-xs font-semibold text-white hover:bg-button-hover disabled:opacity-60" @disabled($preview === null || $preview['error'])>Save and next</button>
                </div>
            </form>
        @endif

        {{-- The reading --}}
        <dl class="grid grid-cols-2 gap-x-3 gap-y-1">
            <dt class="text-label">Previous</dt>
            <dd class="text-right tabular-nums">{{ $panel['previous'] === null ? '—' : Format::quantity($panel['previous']) }}</dd>
            @if ($reading)
                <dt class="text-label">Present</dt>
                <dd class="text-right tabular-nums">{{ $reading->present_reading === null ? 'Estimated' : Format::quantity($reading->present_reading) }}</dd>
                @if ((float) $reading->replaced_meter_consumption > 0)
                    <dt class="text-label">Replaced meter</dt>
                    <dd class="text-right tabular-nums">+{{ Format::quantity($reading->replaced_meter_consumption) }}</dd>
                @endif
                @if ((float) $reading->estimate_deducted > 0)
                    <dt class="text-label">Less estimates billed</dt>
                    <dd class="text-right tabular-nums">−{{ Format::quantity($reading->estimate_deducted) }}</dd>
                @endif
                <dt class="font-semibold text-ink-strong">Usage</dt>
                <dd class="text-right font-semibold tabular-nums text-ink-strong">{{ Format::quantity($reading->usage()) }} cu.m.</dd>
                @if ((float) $reading->consumption > $reading->usage())
                    <dt class="text-label">Billed (minimum)</dt>
                    <dd class="text-right tabular-nums">{{ Format::quantity($reading->consumption) }} cu.m.</dd>
                @endif
                <dt class="text-label">Read on</dt>
                <dd class="text-right">{{ Format::longDate($reading->read_on) }}</dd>
                <dt class="text-label">Entered by</dt>
                <dd class="truncate text-right">{{ $reading->createdBy?->name ?? '—' }}</dd>
            @endif
        </dl>

        {{-- Other ways to record the reading --}}
        @if (! $billed && $periodLabel !== null && ($reading ? $access['edit'] : $access['add']))
            <div>
                <p class="mb-1.5 text-[11px] font-semibold text-label">Couldn't read the meter?</p>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ([ReadingCode::NoAccess, ReadingCode::Defective] as $code)
                        <button
                            type="button"
                            wire:click="markEstimated({{ $customer->id }}, '{{ $code->value }}')"
                            @disabled($reading?->reading_code === $code)
                            class="h-7 rounded-full border border-line px-3 text-xs text-ink-soft hover:border-line-hover disabled:border-accent disabled:text-ink-strong"
                        >{{ $code->label() }}</button>
                    @endforeach
                    @if ($canRollOver && ($rowError || ($panel['preview']['error'] ?? null) || $reading?->reading_code === ReadingCode::Rollover))
                        <button
                            type="button"
                            wire:click="saveRollover({{ $customer->id }})"
                            @disabled($reading?->reading_code === ReadingCode::Rollover)
                            class="h-7 rounded-full border border-line px-3 text-xs text-ink-soft hover:border-line-hover disabled:border-accent disabled:text-ink-strong"
                        >Meter rolled over</button>
                    @endif
                </div>
                <p class="mt-1 text-[11px] text-label">No access and defective meters are billed on an estimate (Rates → setup). The next actual reading deducts it.</p>
            </div>
        @endif

        {{-- Recent usage --}}
        @if ($panel['history'] !== [] || $reading)
            <div>
                <p class="mb-1.5 text-[11px] font-semibold text-label">
                    Usage, last {{ count($panel['history']) }} billed month{{ count($panel['history']) === 1 ? '' : 's' }}
                    @if ($panel['average'] !== null) · 3-month average {{ Format::quantity($panel['average']) }} cu.m. @endif
                </p>
                <div class="flex h-16 items-end gap-1" role="img" aria-label="Monthly usage chart">
                    @foreach ($panel['history'] as $month)
                        <div class="flex-1 rounded-t-xs {{ $month['estimated'] ? 'bg-line' : 'bg-tab-inactive' }}" style="height: {{ max(4, round($month['usage'] / $panel['maxUsage'] * 100)) }}%" title="{{ $month['label'] }}: {{ Format::quantity($month['usage']) }} cu.m.{{ $month['estimated'] ? ' (estimated)' : '' }}"></div>
                    @endforeach
                    @if ($reading)
                        <div class="flex-1 rounded-t-xs {{ $reading->status === MeterReadingStatus::Review ? 'bg-warning' : 'bg-accent' }}" style="height: {{ max(4, round($reading->usage() / $panel['maxUsage'] * 100)) }}%" title="This month: {{ Format::quantity($reading->usage()) }} cu.m."></div>
                    @endif
                </div>
            </div>
        @endif

        {{-- The bill it makes --}}
        @if ($reading)
            <div>
                <p class="mb-1.5 text-[11px] font-semibold text-label">{{ $billed ? 'Billed on invoice '.$panel['billedInvoice'] : 'Bill preview' }}</p>
                @if ($panel['error'])
                    <p class="text-[12px] text-danger">{{ $panel['error'] }}</p>
                @else
                    <dl class="space-y-0.5">
                        @foreach ($panel['lines'] as $line)
                            <div class="flex justify-between gap-3">
                                <dt class="text-ink-soft">{{ $line['description'] }}</dt>
                                <dd class="shrink-0 tabular-nums">{{ Format::amount($line['amount']) }}</dd>
                            </div>
                        @endforeach
                        <div class="flex justify-between gap-3 border-t border-table-line pt-1 font-semibold text-ink-strong">
                            <dt>Current charges</dt>
                            <dd class="tabular-nums">{{ Format::amount($panel['total']) }}</dd>
                        </div>
                    </dl>
                    @if ($billed)
                        <a href="{{ route('invoices.print', $reading->invoice_id) }}" target="_blank" class="mt-1 inline-block text-[11px] font-semibold text-accent underline">Print bill</a>
                    @else
                        <p class="mt-1 text-[11px] text-label">Previous balance and surcharges are worked out again when the bill is generated.</p>
                    @endif
                @endif
            </div>

            {{-- Notes --}}
            <form wire:submit="saveNotes">
                <label for="mr-notes" class="mb-1 block text-[11px] font-semibold text-label">Notes</label>
                <textarea id="mr-notes" wire:model="notes" rows="2" maxlength="500" class="form-control !min-h-0 !text-[13px]" placeholder="Dog in yard, meter box flooded…" @disabled(! $access['edit'])></textarea>
                @error('notes') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                @if ($access['edit'])
                    <div class="mt-1.5 flex items-center justify-between">
                        @if (! $billed && $reading->status !== MeterReadingStatus::Review)
                            <button type="button" class="link-button text-xs" wire:click="reread({{ $customer->id }})" wire:confirm="Clear this reading so the meter can be read again?">Clear reading</button>
                        @else
                            <span></span>
                        @endif
                        <button type="submit" class="form-button form-button-sm">Save notes</button>
                    </div>
                @endif
            </form>
        @endif
    </div>
</aside>
