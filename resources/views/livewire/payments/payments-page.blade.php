@use('App\Livewire\Payments\PaymentsPage')
@use('App\Support\Format')
@use('App\Support\Money')

<x-page title="Payments" guide="payments">
    <div class="max-w-[1100px]">
        <x-flash class="mb-4" />

        @if ($access['add'])
            <form wire:submit="record" novalidate class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <fieldset class="m-0 grid min-w-0 content-start gap-3 border-0 p-0" wire:loading.attr="disabled" wire:target="record">
                    <legend class="mb-2 text-[13px] font-semibold text-ink-strong">Record a payment</legend>

                    @include('livewire.partials.customer-search', ['fieldId' => 'payment-customer'])

                    @if ($chosenCustomer)
                        <p class="-mt-1 text-[13px]">
                            <span class="font-semibold text-ink-strong">{{ $chosenCustomer->first_name }} {{ $chosenCustomer->last_name }}</span>
                            <a href="{{ route('customers.statement', $chosenCustomer) }}" target="_blank" rel="noopener" class="link-button ml-2">Statement of account</a>
                        </p>
                    @endif

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label for="payment-amount" class="mb-1 block text-xs font-semibold text-ink-strong">Amount received</label>
                            <input id="payment-amount" type="text" inputmode="decimal" maxlength="14" wire:model.live.debounce.400ms="amount" placeholder="0.00" class="form-control !h-[34px] text-right !text-[13px]" @error('amount') aria-invalid="true" aria-describedby="payment-amount-error" @enderror>
                            @error('amount') <p id="payment-amount-error" class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="payment-date" class="mb-1 block text-xs font-semibold text-ink-strong">Date received</label>
                            <input id="payment-date" type="date" max="{{ $today }}" wire:model="paidOn" class="form-control !h-[34px] !text-[13px]" @error('paidOn') aria-invalid="true" aria-describedby="payment-date-error" @enderror>
                            @error('paidOn') <p id="payment-date-error" class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="payment-method" class="mb-1 block text-xs font-semibold text-ink-strong">Paid by</label>
                            <select id="payment-method" wire:model.live="method" class="form-control !h-[34px] !text-[13px]" @error('method') aria-invalid="true" @enderror>
                                @foreach ($methods as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('method') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="payment-reference" class="mb-1 block text-xs font-semibold text-ink-strong">
                                Reference no.{{ $chosenMethod?->requiresReference() ? '' : ' (optional)' }}
                            </label>
                            <input id="payment-reference" type="text" maxlength="100" wire:model="referenceNumber" placeholder="{{ $chosenMethod?->requiresReference() ? 'Check / transaction no.' : '' }}" class="form-control !h-[34px] !text-[13px]" @error('referenceNumber') aria-invalid="true" aria-describedby="payment-reference-error" @enderror>
                            @error('referenceNumber') <p id="payment-reference-error" class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <div class="sm:col-span-2">
                            <label for="payment-notes" class="mb-1 block text-xs font-semibold text-ink-strong">Notes (optional)</label>
                            <input id="payment-notes" type="text" maxlength="500" wire:model="notes" class="form-control !h-[34px] !text-[13px]" @error('notes') aria-invalid="true" @enderror>
                            @error('notes') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="form-button min-w-[160px]" wire:loading.attr="disabled" wire:target="record">
                            <span wire:loading.remove wire:target="record">Record Payment</span>
                            <span wire:loading wire:target="record">Saving…</span>
                        </button>
                    </div>
                </fieldset>

                <section aria-label="What the customer owes" class="rounded-xs border border-table-line bg-panel p-4 text-[13px]">
                    @if ($chosenCustomer === null)
                        <p class="text-label">Pick a customer to see their unpaid bills and how this payment will be applied.</p>
                    @else
                        <div class="grid grid-cols-2 gap-3">
                            <p><span class="block text-xs text-label">Owed on unpaid bills</span><span class="text-lg font-semibold text-ink-strong">{{ Format::amount(Money::fromCents($summary['owed'])) }}</span></p>
                            <p><span class="block text-xs text-label">Advance credit</span><span class="text-lg font-semibold text-ink-strong">{{ Format::amount(Money::fromCents($summary['credit'])) }}</span></p>
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
                                            <td>{{ $invoice->invoice_number }} <span class="text-label">· {{ $invoice->periodLabel() ?? $invoice->kind->label() }}</span></td>
                                            <td @class(['font-semibold text-danger' => $invoice->isOverdue($today)])>{{ $invoice->due_date->format('m/d/Y') }}</td>
                                            <td class="cell-right">{{ Format::amount($invoice->balance) }}</td>
                                            <td class="cell-right font-semibold">
                                                @php($applied = $preview['applied'][$invoice->id] ?? 0)
                                                {{ $applied > 0 ? Format::amount(Money::fromCents($applied)) : '—' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            <p class="mt-2 text-xs text-label">Payments go to the bill due first. Existing advance credit is included.</p>
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
            <table class="data-table [&_th]:text-ink-strong [&_tbody_tr]:h-10">
                <thead>
                    <tr>
                        <th scope="col">OR No</th>
                        <th scope="col">Date</th>
                        <th scope="col">Account No</th>
                        <th scope="col">Customer</th>
                        <th scope="col">Paid By</th>
                        <th scope="col" class="cell-right">Amount</th>
                        <th scope="col" class="cell-right">Advance</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="cell-center"><span class="sr-only">View</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($payments as $payment)
                        <tr wire:key="payment-{{ $payment->id }}" @class(['row-selected' => $payment->id === $selected?->id, 'row-inactive' => $payment->isVoid()])>
                            <td class="whitespace-nowrap">{{ $payment->receipt_number }}</td>
                            <td class="whitespace-nowrap">{{ $payment->paid_on->format('m/d/Y') }}</td>
                            <td class="whitespace-nowrap">{{ $payment->customer->account_number }}</td>
                            <td>{{ $payment->customer->first_name }} {{ $payment->customer->last_name }}</td>
                            <td>{{ $payment->method->label() }}</td>
                            <td class="cell-right">{{ Format::amount($payment->amount) }}</td>
                            <td class="cell-right">{{ (float) $payment->unapplied_amount > 0 ? Format::amount($payment->unapplied_amount) : '' }}</td>
                            <td><span @class(['text-[13px]', 'text-muted' => $payment->isVoid(), 'text-success' => ! $payment->isVoid()])>{{ $payment->isVoid() ? 'Void' : 'Posted' }}</span></td>
                            <td class="cell-actions">
                                <button type="button" class="icon-button" wire:click="openPayment({{ $payment->id }})" aria-label="View receipt {{ $payment->receipt_number }}">
                                    <x-icon name="document" :size="14" />
                                </button>
                            </td>
                        </tr>
                    @endforeach
                    <x-filler-rows
                        :count="$payments->count()"
                        :minimum="PaymentsPage::MINIMUM_ROWS"
                        :columns="9"
                        :empty-text="trim($search) === '' ? 'No payments yet.' : 'No payments match.'"
                    />
                </tbody>
            </table>
        </div>

        <x-pagination :paginator="$payments" />
    </div>

    @if ($selected)
        <x-modal :title="'Official Receipt '.$selected->receipt_number" close="$wire.closeModal()" width="md">
            @if ($selected->isVoid())
                <x-alert tone="error" class="mt-3">Void: {{ $selected->void_reason }} ({{ $selected->voidedBy?->name }}, {{ $selected->voided_at?->format('m/d/Y') }})</x-alert>
            @endif

            <dl class="mt-3 grid grid-cols-2 gap-3 text-[13px]">
                <div><dt class="text-xs text-label">Customer</dt><dd>{{ $selected->customer->first_name }} {{ $selected->customer->last_name }} ({{ $selected->customer->account_number }})</dd></div>
                <div><dt class="text-xs text-label">Date received</dt><dd>{{ $selected->paid_on->format('m/d/Y') }}</dd></div>
                <div><dt class="text-xs text-label">Amount</dt><dd class="font-semibold">{{ Format::amount($selected->amount) }}</dd></div>
                <div><dt class="text-xs text-label">Paid by</dt><dd>{{ $selected->method->label() }}{{ $selected->reference_number ? ' · '.$selected->reference_number : '' }}</dd></div>
                <div><dt class="text-xs text-label">Received by</dt><dd>{{ $selected->receivedBy?->name ?? '—' }}</dd></div>
                <div><dt class="text-xs text-label">Advance credit left</dt><dd>{{ Format::amount($selected->unapplied_amount) }}</dd></div>
                @if ($selected->notes)
                    <div class="col-span-2"><dt class="text-xs text-label">Notes</dt><dd>{{ $selected->notes }}</dd></div>
                @endif
            </dl>

            @if ($selected->allocations->isNotEmpty())
                <h3 class="mt-4 text-[13px] font-semibold text-ink-strong">Applied to</h3>
                <ul class="mt-1 grid gap-1 text-[13px]">
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
                    <label for="void-payment-reason" class="block text-xs font-semibold text-danger">Why is this payment being voided?</label>
                    <textarea id="void-payment-reason" wire:model="voidReason" rows="2" maxlength="255" class="form-control mt-1 !text-[13px]" placeholder="e.g. Recorded for the wrong customer" @error('voidReason') aria-invalid="true" @enderror></textarea>
                    @error('voidReason') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                    <p class="mt-2 text-xs text-ink-soft">Voiding can't be undone. The bills it paid become unpaid again.</p>
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
