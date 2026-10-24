@use('App\Livewire\Payments\PaymentsPage')
@use('App\Support\Format')
@use('App\Support\Money')

<x-page title="Payments" guide="payments">
    <div class="max-w-[1280px]">
        <x-flash class="mb-4" />

        @if ($access['add'])
            <form wire:submit="record" novalidate class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <fieldset class="m-0 grid min-w-0 content-start gap-3 border-0 p-0" wire:loading.attr="disabled" wire:target="record">
                    <legend class="mb-2 text-body font-semibold text-ink-strong">Record a payment</legend>

                    @include('livewire.partials.customer-search', ['fieldId' => 'payment-customer'])

                    @if ($chosenCustomer)
                        <p class="-mt-1 text-body">
                            <span class="font-semibold text-ink-strong">{{ $chosenCustomer->first_name }} {{ $chosenCustomer->last_name }}</span>
                            <a href="{{ route('customers.statement', $chosenCustomer) }}" target="_blank" rel="noopener" class="link-button ml-2">Statement of account</a>
                        </p>
                    @endif

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label for="payment-amount" class="mb-1 block text-caption font-semibold text-ink-strong">Amount received<x-required-mark /></label>
                            <input id="payment-amount" aria-required="true" type="text" inputmode="decimal" maxlength="14" wire:model.live.debounce.400ms="amount" placeholder="0.00" class="form-control !h-[34px] text-right !text-body" @error('amount') aria-invalid="true" aria-describedby="payment-amount-error" @enderror>
                            @error('amount') <p id="payment-amount-error" class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="payment-date" class="mb-1 block text-caption font-semibold text-ink-strong">Date received<x-required-mark /></label>
                            <input id="payment-date" aria-required="true" type="date" max="{{ $today }}" wire:model="paidOn" class="form-control !h-[34px] !text-body" @error('paidOn') aria-invalid="true" aria-describedby="payment-date-error" @enderror>
                            @error('paidOn') <p id="payment-date-error" class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="payment-method" class="mb-1 block text-caption font-semibold text-ink-strong">Paid by<x-required-mark /></label>
                            <select id="payment-method" aria-required="true" wire:model.live="method" class="form-control !h-[34px] !text-body" @error('method') aria-invalid="true" @enderror>
                                @foreach ($methods as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('method') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="payment-reference" class="mb-1 block text-caption font-semibold text-ink-strong">
                                Reference no.@if ($chosenMethod?->requiresReference())<x-required-mark />@else{{ ' (optional)' }}@endif
                            </label>
                            <input id="payment-reference" type="text" @if ($chosenMethod?->requiresReference()) aria-required="true" @endif maxlength="100" wire:model="referenceNumber" placeholder="{{ $chosenMethod?->requiresReference() ? 'Check / transaction no.' : '' }}" class="form-control !h-[34px] !text-body" @error('referenceNumber') aria-invalid="true" aria-describedby="payment-reference-error" @enderror>
                            @error('referenceNumber') <p id="payment-reference-error" class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <div class="sm:col-span-2">
                            <label for="payment-notes" class="mb-1 block text-caption font-semibold text-ink-strong">Notes (optional)</label>
                            <input id="payment-notes" type="text" maxlength="500" wire:model="notes" class="form-control !h-[34px] !text-body" @error('notes') aria-invalid="true" @enderror>
                            @error('notes') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="form-button min-w-[160px]" wire:loading.attr="disabled" wire:target="record">
                            <span wire:loading.remove wire:target="record">Record Payment</span>
                            <span wire:loading wire:target="record">Checking…</span>
                        </button>
                    </div>
                </fieldset>

                <section aria-label="What the customer owes" class="rounded-xs border border-table-line bg-panel p-4 text-body">
                    @if ($chosenCustomer === null)
                        <p class="text-label">Pick a customer to see their unpaid bills and how this payment will be applied.</p>
                    @else
                        <div class="grid grid-cols-2 gap-3">
                            <p><span class="block text-caption text-label">Owed on unpaid bills</span><span class="text-heading font-semibold text-ink-strong">{{ Format::amount(Money::fromCents($summary['owed'])) }}</span></p>
                            <p><span class="block text-caption text-label">Advance credit</span><span class="text-heading font-semibold text-ink-strong">{{ Format::amount(Money::fromCents($summary['credit'])) }}</span></p>
                        </div>

                        @if ($openInvoices->isEmpty())
                            <p class="mt-3 text-label">No unpaid bills. A payment now is kept as advance credit for the next bill.</p>
                        @else
                            <table class="data-table table-compact mt-3">
                                <thead>
                                    <tr>
                                        <th scope="col">Invoice</th>
                                        <th scope="col">Due</th>
                                        <th scope="col" class="cell-right">Balance</th>
                                        <th scope="col" class="cell-right">This payment</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($openInvoices as $invoice)
                                        <tr wire:key="open-invoice-{{ $invoice->id }}">
                                            <td>
                                                <span class="block font-medium text-ink-strong tabular-nums">{{ $invoice->invoice_number }}</span>
                                                <span class="block text-caption text-label">{{ $invoice->kind->label() }}{{ $invoice->periodLabel() ? ' · '.$invoice->periodLabel() : '' }}</span>
                                            </td>
                                            <td @class(['text-caption whitespace-nowrap tabular-nums', 'font-semibold text-danger' => $invoice->isOverdue($today), 'text-label' => ! $invoice->isOverdue($today)])>{{ Format::shortDate($invoice->due_date) }}</td>
                                            <td class="cell-right">{{ Format::amount($invoice->balance) }}</td>
                                            <td class="cell-right font-semibold">
                                                @php($applied = $preview['applied'][$invoice->id] ?? 0)
                                                {{ $applied > 0 ? Format::amount(Money::fromCents($applied)) : '—' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            <p class="mt-2 text-caption text-label">Payments go to the bill due first. Existing advance credit is included.</p>
                        @endif

                        @if ($preview['advance'] > 0)
                            <p class="mt-2 font-semibold text-success">{{ Format::amount(Money::fromCents($preview['advance'])) }} will be kept as advance credit.</p>
                        @endif
                    @endif
                </section>
            </form>

            <hr class="mt-6 border-0 border-t border-line">
        @endif

        <div class="mt-5 flex flex-wrap items-center gap-3">
            <x-search-box label="Search payments" :width="240" wire:model.live.debounce.300ms="search" />
        </div>

        <div class="table-scroller mt-3">
            {{-- On narrow screens the Method (4th) and Advance (6th) columns are hidden, filler rows included. --}}
            <table class="data-table max-md:[&_tr>*:nth-child(4)]:hidden max-md:[&_tr>*:nth-child(6)]:hidden">
                <thead>
                    <tr>
                        <th scope="col" class="w-px">OR No</th>
                        <th scope="col">Customer</th>
                        <th scope="col" class="w-px">Paid On</th>
                        <th scope="col">Method</th>
                        <th scope="col" class="cell-right">Amount</th>
                        <th scope="col" class="cell-right">Advance</th>
                        <th scope="col" class="cell-center w-px">Status</th>
                        <th scope="col" class="cell-center"><span class="sr-only">View</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($payments as $payment)
                        @php($isVoid = $payment->isVoid())
                        <tr
                            wire:key="payment-{{ $payment->id }}"
                            wire:click="openPayment({{ $payment->id }})"
                            @class(['row-clickable', 'row-selected' => $payment->id === $selected?->id, 'row-inactive' => $isVoid])
                        >
                            <td @class(['whitespace-nowrap tabular-nums', 'font-medium text-ink-strong' => ! $isVoid])>{{ $payment->receipt_number }}</td>
                            <td>
                                <span @class(['block', 'font-medium text-ink-strong' => ! $isVoid])>{{ $payment->customer->first_name }} {{ $payment->customer->last_name }}</span>
                                <span class="mt-0.5 block text-caption whitespace-nowrap text-label tabular-nums">Acct No: {{ $payment->customer->account_number }}</span>
                            </td>
                            <td class="text-caption whitespace-nowrap text-label tabular-nums">{{ Format::shortDate($payment->paid_on) }}</td>
                            <td>{{ $payment->method->label() }}</td>
                            <td @class(['cell-right', 'font-semibold text-ink-strong' => ! $isVoid])>{{ Format::amount($payment->amount) }}</td>
                            <td class="cell-right">{{ (float) $payment->unapplied_amount > 0 ? Format::amount($payment->unapplied_amount) : '' }}</td>
                            <td class="cell-center"><span @class(['status-pill', 'text-muted' => $isVoid, 'text-success' => ! $isVoid])>{{ $isVoid ? 'Void' : 'Posted' }}</span></td>
                            <td class="cell-actions">
                                <button type="button" class="icon-button" wire:click.stop="openPayment({{ $payment->id }})" aria-label="View receipt {{ $payment->receipt_number }}">
                                    <x-icon name="document" :size="14" />
                                </button>
                            </td>
                        </tr>
                    @endforeach
                    <x-filler-rows
                        :count="$payments->count()"
                        :minimum="PaymentsPage::MINIMUM_ROWS"
                        :columns="8"
                        :empty-text="trim($search) === '' ? 'No payments yet.' : 'No payments match.'"
                    />
                </tbody>
            </table>
        </div>

        <x-pagination :paginator="$payments" />
    </div>

    @if ($confirming)
        @php($amountCents = Money::toCents(trim($amount)))
        @php($overpaid = $summary['owed'] > 0 ? $amountCents - $summary['owed'] : 0)
        <x-modal title="Confirm Payment" close="$wire.cancelConfirmation()" width="md">
            <p class="mt-1 text-body text-ink-soft">Check the details below. The payment is saved only when you click Confirm &amp; Save.</p>

            @if ($overpaid > 0)
                <x-alert tone="error" class="mt-3">
                    This is {{ Format::amount(Money::fromCents($overpaid)) }} more than the {{ Format::amount(Money::fromCents($summary['owed'])) }} owed. The extra will be kept as advance credit.
                </x-alert>
            @endif

            <dl class="mt-3 grid grid-cols-2 gap-3 text-body">
                <div class="col-span-2"><dt class="text-caption text-label">Customer</dt><dd>{{ $chosenCustomer->first_name }} {{ $chosenCustomer->last_name }} ({{ $chosenCustomer->account_number }})</dd></div>
                <div><dt class="text-caption text-label">Amount received</dt><dd class="text-heading font-semibold text-ink-strong">{{ Format::amount(Money::fromCents($amountCents)) }}</dd></div>
                <div><dt class="text-caption text-label">Date received</dt><dd>{{ Format::longDate(\Illuminate\Support\Carbon::parse($paidOn)) }}</dd></div>
                <div><dt class="text-caption text-label">Paid by</dt><dd>{{ $chosenMethod?->label() }}</dd></div>
                <div><dt class="text-caption text-label">Reference no.</dt><dd>{{ trim($referenceNumber) !== '' ? trim($referenceNumber) : '—' }}</dd></div>
                @if (trim($notes) !== '')
                    <div class="col-span-2"><dt class="text-caption text-label">Notes</dt><dd>{{ trim($notes) }}</dd></div>
                @endif
            </dl>

            <h3 class="mt-4 text-body font-semibold text-ink-strong">Will be applied to</h3>
            @if ($preview['applied'] === [])
                <p class="mt-1 text-body text-label">No unpaid bills.</p>
            @else
                <ul class="mt-1 grid gap-1 text-body">
                    @foreach ($openInvoices->filter(fn ($invoice) => isset($preview['applied'][$invoice->id])) as $invoice)
                        <li wire:key="confirm-invoice-{{ $invoice->id }}">
                            Invoice {{ $invoice->invoice_number }} · {{ $invoice->periodLabel() ?? $invoice->kind->label() }}
                            · <span class="font-semibold">{{ Format::amount(Money::fromCents($preview['applied'][$invoice->id])) }}</span>
                        </li>
                    @endforeach
                </ul>
                @if ($summary['credit'] > 0)
                    <p class="mt-1 text-caption text-label">Includes {{ Format::amount(Money::fromCents($summary['credit'])) }} of existing advance credit.</p>
                @endif
            @endif
            @if ($preview['advance'] > 0)
                <p class="mt-2 text-body font-semibold text-success">{{ Format::amount(Money::fromCents($preview['advance'])) }} will be kept as advance credit.</p>
            @endif

            <div class="form-actions mt-5">
                <button type="button" class="text-button" wire:click="cancelConfirmation" wire:loading.attr="disabled" wire:target="confirmRecord">Back</button>
                <button type="button" class="form-button min-w-[160px]" wire:click="confirmRecord" wire:loading.attr="disabled" wire:target="confirmRecord">
                    <span wire:loading.remove wire:target="confirmRecord">Confirm &amp; Save</span>
                    <span wire:loading wire:target="confirmRecord">Saving…</span>
                </button>
            </div>
        </x-modal>
    @endif

    @if ($selected)
        <x-modal :title="'Official Receipt '.$selected->receipt_number" close="$wire.closeModal()" width="md">
            @if ($selected->isVoid())
                <x-alert tone="error" class="mt-3">Void: {{ $selected->void_reason }} ({{ $selected->voidedBy?->name }}, {{ $selected->voided_at ? Format::date($selected->voided_at, $timezone) : '' }})</x-alert>
            @endif

            <dl class="mt-3 grid grid-cols-2 gap-3 text-body">
                <div><dt class="text-caption text-label">Customer</dt><dd>{{ $selected->customer->first_name }} {{ $selected->customer->last_name }} ({{ $selected->customer->account_number }})</dd></div>
                <div><dt class="text-caption text-label">Date received</dt><dd>{{ Format::longDate($selected->paid_on) }}</dd></div>
                <div><dt class="text-caption text-label">Amount</dt><dd class="font-semibold">{{ Format::amount($selected->amount) }}</dd></div>
                <div><dt class="text-caption text-label">Paid by</dt><dd>{{ $selected->method->label() }}{{ $selected->reference_number ? ' · '.$selected->reference_number : '' }}</dd></div>
                <div><dt class="text-caption text-label">Received by</dt><dd>{{ $selected->receivedBy?->name ?? '—' }}</dd></div>
                <div><dt class="text-caption text-label">Advance credit left</dt><dd>{{ Format::amount($selected->unapplied_amount) }}</dd></div>
                @if ($selected->notes)
                    <div class="col-span-2"><dt class="text-caption text-label">Notes</dt><dd>{{ $selected->notes }}</dd></div>
                @endif
            </dl>

            @if ($selected->allocations->isNotEmpty())
                <h3 class="mt-4 text-body font-semibold text-ink-strong">Applied to</h3>
                <ul class="mt-1 grid gap-1 text-body">
                    @foreach ($selected->allocations as $allocation)
                        <li wire:key="paid-{{ $allocation->id }}">
                            Invoice {{ $allocation->invoice->invoice_number }} · {{ $allocation->invoice->periodLabel() ?? $allocation->invoice->kind->label() }}
                            · <span class="font-semibold">{{ Format::amount($allocation->amount) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($voiding)
                <form wire:submit="voidPayment" class="mt-4 rounded-xs border border-danger bg-danger-bg p-3" novalidate>
                    <label for="void-payment-reason" class="block text-caption font-semibold text-danger">Why is this payment being voided?<x-required-mark /></label>
                    <textarea id="void-payment-reason" aria-required="true" wire:model="voidReason" rows="2" maxlength="255" class="form-control mt-1 !text-body" placeholder="e.g. Recorded for the wrong customer" @error('voidReason') aria-invalid="true" @enderror></textarea>
                    @error('voidReason') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                    <p class="mt-2 text-caption text-ink-soft">Voiding can't be undone. The bills it paid become unpaid again.</p>
                    <div class="form-actions mt-3">
                        <button type="button" class="text-button" wire:click="$set('voiding', false)">Back</button>
                        <button type="submit" class="form-button min-w-[120px]">Void Payment</button>
                    </div>
                </form>
            @endif

            <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap gap-3">
                    <a href="{{ route('payments.receipt', $selected) }}" target="_blank" rel="noopener" class="link-button">Print receipt</a>
                    <a href="{{ route('customers.statement', $selected->customer) }}" target="_blank" rel="noopener" class="link-button">Statement of account</a>
                    @if ($access['delete'] && ! $selected->isVoid() && ! $voiding)
                        <button type="button" class="link-button text-danger" wire:click="$set('voiding', true)">Void payment</button>
                    @endif
                </div>
                <button type="button" class="form-button min-w-[100px]" wire:click="closeModal">Close</button>
            </div>
        </x-modal>
    @endif
</x-page>
