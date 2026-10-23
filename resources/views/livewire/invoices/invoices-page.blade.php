@use('App\Enums\OverdueHandling')
@use('App\Livewire\Invoices\InvoicesPage')
@use('App\Support\Format')

<x-page title="Invoices" guide="invoices">
    <div class="max-w-[1280px]">
        <x-flash class="mb-4" />

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border border-table-line bg-card px-4 py-3 shadow-card">
                <p class="text-xs text-label">Outstanding</p>
                <p class="mt-1 text-lg font-semibold text-ink-strong">{{ Format::amount($outstanding) }}</p>
            </div>
            <div @class(['rounded-xl border px-4 py-3 shadow-card', 'border-danger bg-danger-bg' => $overdueCount > 0, 'border-table-line bg-card' => $overdueCount === 0])>
                <p class="text-xs text-label">Overdue</p>
                <p @class(['mt-1 text-lg font-semibold', 'text-danger' => $overdueCount > 0, 'text-ink-strong' => $overdueCount === 0])>
                    {{ Format::amount($overdueAmount) }}
                    <span class="text-xs font-normal">· {{ $overdueCount }} invoice{{ $overdueCount === 1 ? '' : 's' }}</span>
                </p>
            </div>
            <div class="flex flex-col items-start justify-center gap-2 rounded-xl border border-table-line bg-card px-4 py-3 shadow-card">
                @if ($access['add'])
                    <a href="{{ route('invoices.generate') }}" class="form-button form-button-sm inline-flex min-w-40 items-center justify-center">Generate Bills</a>
                @endif
                @if ($access['edit'])
                    <button type="button" class="link-button" wire:click="openSettings">Billing settings</button>
                @endif
            </div>
        </div>

        @if ($awaitingSurcharge > 0 && $access['edit'])
            <x-alert tone="error" class="mt-4 flex flex-wrap items-center gap-3">
                <span>{{ $awaitingSurcharge }} overdue invoice{{ $awaitingSurcharge === 1 ? ' has' : 's have' }} no surcharge yet. Surcharges are also added automatically every night.</span>
                <button type="button" class="underline underline-offset-2" wire:click="applySurcharges" wire:loading.attr="disabled">Add surcharges now</button>
            </x-alert>
        @endif

        <div class="mt-5 flex flex-wrap items-end gap-3">
            <x-search-box label="Search invoices" :width="220" wire:model.live.debounce.300ms="search" />
            <div>
                <label for="invoice-status-filter" class="sr-only">Status</label>
                <select id="invoice-status-filter" wire:model.live="statusFilter" class="form-control !h-[32px] !w-44 !text-[13px]">
                    <option value="">All statuses</option>
                    @foreach (InvoicesPage::STATUS_FILTERS as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="invoice-period-filter" class="sr-only">Billing month</label>
                <input id="invoice-period-filter" type="month" wire:model.live="periodFilter" class="form-control !h-[32px] !w-44 !text-[13px]" title="Billing month">
            </div>
        </div>

        <div class="table-scroller mt-3">
            {{-- On narrow screens the For (3rd) and Balance (6th) columns are hidden, filler rows included. --}}
            <table class="data-table max-md:[&_tr>*:nth-child(3)]:hidden max-md:[&_tr>*:nth-child(6)]:hidden">
                <thead>
                    <tr>
                        <th scope="col" class="w-px">Invoice No</th>
                        <th scope="col">Customer</th>
                        <th scope="col">For</th>
                        <th scope="col" class="w-px">Dates</th>
                        <th scope="col" class="cell-right">Amount</th>
                        <th scope="col" class="cell-right">Balance</th>
                        <th scope="col" class="cell-center w-px">Status</th>
                        <th scope="col" class="cell-center"><span class="sr-only">View</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($invoices as $invoice)
                        @php
                            $isVoid = $invoice->status->value === 'void';
                            $isOverdue = $invoice->isOverdue($today);
                        @endphp
                        <tr
                            wire:key="invoice-{{ $invoice->id }}"
                            wire:click="openInvoice({{ $invoice->id }})"
                            @class(['row-clickable', 'row-selected' => $invoice->id === $selected?->id, 'row-inactive' => $isVoid])
                        >
                            <td @class(['whitespace-nowrap tabular-nums', 'font-medium text-ink-strong' => ! $isVoid])>{{ $invoice->invoice_number }}</td>
                            <td>
                                <span @class(['block', 'font-medium text-ink-strong' => ! $isVoid])>{{ $invoice->customer->first_name }} {{ $invoice->customer->last_name }}</span>
                                <span class="mt-0.5 block text-xs whitespace-nowrap text-label">Acct No: {{ $invoice->customer->account_number }}</span>
                            </td>
                            <td class="whitespace-nowrap">
                                <span @class(['block', 'font-medium text-ink-strong' => ! $isVoid])>{{ $invoice->kind->label() }}</span>
                                @if ($invoice->periodLabel())
                                    <span class="mt-0.5 block text-xs text-label">{{ $invoice->periodLabel() }}</span>
                                @endif
                            </td>
                            <td class="text-xs whitespace-nowrap tabular-nums">
                                <span class="block text-label">Billed: {{ Format::shortDate($invoice->invoice_date) }}</span>
                                <span @class(['mt-0.5 block', 'font-semibold text-danger' => $isOverdue, 'text-label' => ! $isOverdue])>Due: {{ Format::shortDate($invoice->due_date) }}</span>
                            </td>
                            <td class="cell-right">{{ Format::amount($invoice->total_amount) }}</td>
                            <td @class(['cell-right', 'font-semibold text-ink-strong' => ! $isVoid && (float) $invoice->balance > 0])>{{ Format::amount($invoice->balance) }}</td>
                            <td class="cell-center"><span class="status-pill {{ $invoice->statusColorClass($today) }}">{{ $invoice->statusLabel($today) }}</span></td>
                            <td class="cell-actions">
                                <button type="button" class="icon-button" wire:click.stop="openInvoice({{ $invoice->id }})" aria-label="View invoice {{ $invoice->invoice_number }}">
                                    <x-icon name="document" :size="14" />
                                </button>
                            </td>
                        </tr>
                    @endforeach
                    <x-filler-rows
                        :count="$invoices->count()"
                        :minimum="InvoicesPage::MINIMUM_ROWS"
                        :columns="8"
                        :empty-text="trim($search) === '' && $statusFilter === '' && $periodFilter === '' ? 'No invoices yet. Use Generate Bills to bill your customers.' : 'No invoices match.'"
                    />
                </tbody>
            </table>
        </div>

        <x-pagination :paginator="$invoices" />
    </div>

    @if ($selected)
        @include('livewire.invoices.partials.invoice-modal')
    @endif

    @if ($settingsOpen)
        <x-modal title="Billing Settings" close="$wire.closeSettings()" width="md">
            <form wire:submit="saveSettings" class="mt-3">
                <fieldset class="m-0 min-w-0 border-0 p-0">
                    <legend class="text-[13px] font-semibold text-ink-strong">When a bill isn't paid by its due date<x-required-mark /></legend>
                    <p class="mt-1 text-xs text-label">After-due charges (e.g. Surcharge) from the customer's charge type are applied once per overdue bill.</p>
                    <div class="mt-3 grid gap-3">
                        @foreach (OverdueHandling::cases() as $option)
                            <label wire:key="overdue-{{ $option->value }}" class="flex items-start gap-2 text-[13px] text-ink">
                                <input type="radio" name="overdue-handling" value="{{ $option->value }}" wire:model="overdueHandling" class="mt-0.5 size-4 accent-accent">
                                <span>
                                    {{ $option->label() }}
                                    <span class="block text-xs text-label">
                                        {{ $option === OverdueHandling::NextBill
                                            ? 'The unpaid amount appears as "Previous balance" on the next monthly bill, and the surcharge is a line on that bill.'
                                            : 'The surcharge is added to the overdue invoice (its total grows). Applied every night, or with "Add surcharges now".' }}
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('overdueHandling') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </fieldset>
                <p class="mt-4 text-xs text-label">Due-date days, currency and the billing headers and footers are in Company Setup → Operation Setup.</p>
                <div class="form-actions mt-5">
                    <button type="button" class="text-button" wire:click="closeSettings">Cancel</button>
                    <button type="submit" class="form-button min-w-[100px]">Save</button>
                </div>
            </form>
        </x-modal>
    @endif
</x-page>
