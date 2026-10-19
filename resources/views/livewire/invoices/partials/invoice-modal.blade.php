@use('App\Enums\AdjustmentType')
@use('App\Enums\InvoiceStatus')
@use('App\Support\Format')

@php
    $invoice = $selected;
    $customer = $invoice->customer;
    $reading = $invoice->meterReading;
    $isVoid = $invoice->status === InvoiceStatus::Void;
    $payments = $invoice->allocations->filter(fn ($allocation) => $allocation->payment && ! $allocation->payment->isVoid());
@endphp

<x-modal :title="'Invoice '.$invoice->invoice_number" close="$wire.closeModal()" width="lg">
    <div class="mt-3 grid gap-2">
        @if ($isVoid)
            <x-alert tone="error">Void: {{ $invoice->void_reason }} ({{ $invoice->voidedBy?->name }}, {{ $invoice->voided_at?->format('m/d/Y') }})</x-alert>
        @elseif ($invoice->isOverdue($today))
            <x-alert tone="error">Overdue since {{ $invoice->due_date->format('m/d/Y') }}.</x-alert>
        @endif
    </div>

    <div class="mt-3 grid gap-4 text-[13px] sm:grid-cols-3">
        <div>
            <p class="text-xs font-medium text-label">Customer</p>
            <p class="mt-0.5 font-semibold text-ink-strong">{{ $customer->first_name }} {{ $customer->last_name }}</p>
            <p class="text-label">Account {{ $customer->account_number }}{{ $customer->meter_number ? ' · Meter '.$customer->meter_number : '' }}</p>
        </div>
        <div>
            <p class="text-xs font-medium text-label">For</p>
            <p class="mt-0.5">{{ $invoice->periodLabel() ?? $invoice->kind->label() }}</p>
            @if ($invoice->workOrder)
                <p class="text-label">Work order {{ $invoice->workOrder->order_number }}</p>
            @endif
        </div>
        <div>
            <p class="text-xs font-medium text-label">Dates</p>
            <p class="mt-0.5">Issued {{ $invoice->invoice_date->format('m/d/Y') }} · Due <span @class(['font-semibold text-danger' => $invoice->isOverdue($today)])>{{ $invoice->due_date->format('m/d/Y') }}</span></p>
            <p class="{{ $invoice->statusColorClass($today) }}">{{ $invoice->statusLabel($today) }}</p>
        </div>
    </div>

    @if ($reading)
        <div class="mt-3 grid grid-cols-2 gap-2 rounded-xs bg-panel px-3 py-2 text-[13px] sm:grid-cols-4">
            <p><span class="block text-xs text-label">Previous reading</span>{{ Format::quantity($reading->previous_reading) }}</p>
            <p><span class="block text-xs text-label">Present reading</span>{{ $reading->present_reading === null ? 'Defective meter' : Format::quantity($reading->present_reading) }}</p>
            <p><span class="block text-xs text-label">Billed consumption</span>{{ Format::quantity($reading->consumption) }} cu.m.</p>
            <p><span class="block text-xs text-label">Read on</span>{{ $reading->read_on->format('m/d/Y') }}</p>
        </div>
    @endif

    <div class="table-scroller mt-3">
        <table class="data-table table-compact">
            <thead>
                <tr>
                    <th scope="col">Description</th>
                    <th scope="col" class="cell-right">Cu.m.</th>
                    <th scope="col" class="cell-right">Rate</th>
                    <th scope="col" class="cell-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice->lines as $line)
                    <tr wire:key="line-{{ $line->id }}">
                        <td>{{ $line->description }}</td>
                        <td class="cell-right">{{ $line->quantity === null ? '' : Format::quantity($line->quantity) }}</td>
                        <td class="cell-right">{{ $line->rate === null ? '' : Format::amount($line->rate) }}</td>
                        <td @class(['cell-right', 'text-success' => (float) $line->amount < 0])>{{ Format::amount($line->amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th scope="row" colspan="3" class="cell-right">Total for this invoice</th>
                    <td class="cell-right font-semibold">{{ Format::amount($invoice->total_amount) }}</td>
                </tr>
                <tr>
                    <th scope="row" colspan="3" class="cell-right">Paid</th>
                    <td class="cell-right">{{ Format::amount($invoice->amount_paid) }}</td>
                </tr>
                <tr>
                    <th scope="row" colspan="3" class="cell-right">Balance</th>
                    <td class="cell-right font-semibold">{{ Format::amount($invoice->balance) }}</td>
                </tr>
                @if ((float) $invoice->previous_balance > 0)
                    <tr>
                        <th scope="row" colspan="3" class="cell-right font-normal text-label">Unpaid on earlier bills when issued</th>
                        <td class="cell-right text-label">{{ Format::amount($invoice->previous_balance) }}</td>
                    </tr>
                @endif
            </tfoot>
        </table>
    </div>

    @if ($payments->isNotEmpty())
        <h3 class="mt-4 text-[13px] font-semibold text-ink-strong">Payments applied</h3>
        <ul class="mt-1 grid gap-1 text-[13px]">
            @foreach ($payments as $allocation)
                <li wire:key="allocation-{{ $allocation->id }}">
                    OR {{ $allocation->payment->receipt_number }} · {{ $allocation->payment->paid_on->format('m/d/Y') }}
                    · {{ $allocation->payment->method->label() }} · <span class="font-semibold">{{ Format::amount($allocation->amount) }}</span>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($invoice->adjustments->isNotEmpty())
        <h3 class="mt-4 text-[13px] font-semibold text-ink-strong">Credit and debit memos</h3>
        <ul class="mt-1 grid gap-2 text-[13px]">
            @foreach ($invoice->adjustments as $memo)
                <li wire:key="memo-{{ $memo->id }}" @class(['rounded-xs border border-table-line px-3 py-2', 'opacity-60' => $memo->isVoid()])>
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p>
                            <span class="font-semibold text-ink-strong">{{ $memo->type->label() }} {{ $memo->label() }}</span>
                            · {{ $memo->reason_category->label() }} · {{ $memo->issued_on->format('m/d/Y') }}
                            @if ($memo->workOrder) · work order {{ $memo->workOrder->order_number }} @endif
                        </p>
                        <p @class(['font-semibold', 'text-success' => $memo->type === AdjustmentType::Credit, 'line-through' => $memo->isVoid()])>
                            {{ $memo->type === AdjustmentType::Credit ? '−' : '+' }}{{ Format::amount($memo->amount) }}
                        </p>
                    </div>
                    <p class="text-ink-soft">{{ $memo->reason }}</p>
                    <p class="text-xs text-label">
                        By {{ $memo->createdBy?->name ?? '—' }}
                        @if ($memo->isVoid())
                            · <span class="text-danger">Void: {{ $memo->void_reason }}</span>
                        @endif
                    </p>
                    <div class="mt-1 flex flex-wrap gap-3">
                        <a href="{{ route('invoices.memo', $memo) }}" target="_blank" rel="noopener" class="link-button">Print memo</a>
                        @if ($access['delete'] && ! $memo->isVoid() && ! $isVoid && $voidingMemoId !== (string) $memo->id)
                            <button type="button" class="link-button text-danger" wire:click="askVoidMemo({{ $memo->id }})">Void memo</button>
                        @endif
                    </div>
                    @if ($voidingMemoId === (string) $memo->id)
                        <form wire:submit="voidMemo" class="mt-2 rounded-xs border border-danger bg-danger-bg p-2" novalidate>
                            <label for="void-memo-reason" class="block text-xs font-semibold text-danger">Why is {{ $memo->label() }} being voided?</label>
                            <input id="void-memo-reason" type="text" maxlength="255" wire:model="memoVoidReason" class="form-control mt-1 !h-[32px] !text-[13px]" @error('memoVoidReason') aria-invalid="true" @enderror>
                            @error('memoVoidReason') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                            <p class="mt-1 text-xs text-ink-soft">A reversing line puts the invoice back to its amount before the memo.</p>
                            <div class="form-actions mt-2">
                                <button type="button" class="text-button" wire:click="$set('voidingMemoId', '')">Back</button>
                                <button type="submit" class="form-button form-button-sm min-w-[110px]">Void Memo</button>
                            </div>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($adjusting)
        <form
            wire:submit="saveAdjustment"
            novalidate
            class="mt-4 rounded-xs border border-line bg-panel p-3"
            x-data
        >
            <fieldset class="m-0 min-w-0 border-0 p-0" wire:loading.attr="disabled" wire:target="saveAdjustment">
                <legend class="text-[13px] font-semibold text-ink-strong">Adjust invoice {{ $invoice->invoice_number }}</legend>

                <div class="mt-2 flex flex-wrap gap-4 text-[13px]">
                    <label class="flex items-start gap-2">
                        <input type="radio" name="adjust-type" value="credit" wire:model.live="adjust.type" class="mt-0.5 size-4 accent-accent" @disabled(! $access['delete'])>
                        <span>
                            <span class="font-semibold">Credit memo</span> — lowers what the customer owes
                            @unless ($access['delete']) <span class="block text-xs text-label">Needs Delete access on Invoices.</span> @endunless
                        </span>
                    </label>
                    <label class="flex items-start gap-2">
                        <input type="radio" name="adjust-type" value="debit" wire:model.live="adjust.type" class="mt-0.5 size-4 accent-accent">
                        <span><span class="font-semibold">Debit memo</span> — adds to what the customer owes</span>
                    </label>
                </div>
                @error('adjust.type') <p class="form-error" role="alert">{{ $message }}</p> @enderror

                <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    @if ($adjustType === AdjustmentType::Credit && $flaggedWorkOrders->isNotEmpty())
                        <div class="sm:col-span-2">
                            <label for="adjust-work-order" class="mb-1 block text-xs font-semibold text-ink-strong">Resolves flagged work order (optional)</label>
                            <select id="adjust-work-order" wire:model.live="adjust.workOrderId" class="form-control !h-[34px] !text-[13px]" @error('adjust.workOrderId') aria-invalid="true" @enderror>
                                <option value="">None</option>
                                @foreach ($flaggedWorkOrders as $order)
                                    <option value="{{ $order->id }}" wire:key="flagged-{{ $order->id }}">
                                        {{ $order->order_number }} · {{ $order->order_type->label() }} · {{ $order->review_flag->label() }}
                                    </option>
                                @endforeach
                            </select>
                            @error('adjust.workOrderId') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                            <p class="mt-1 text-xs text-label">Picking one fills in the reason and marks its flag resolved.</p>
                        </div>
                    @endif
                    <div>
                        <label for="adjust-category" class="mb-1 block text-xs font-semibold text-ink-strong">Reason</label>
                        <select id="adjust-category" wire:model="adjust.category" class="form-control !h-[34px] !text-[13px]" @error('adjust.category') aria-invalid="true" @enderror>
                            <option value="">Select</option>
                            @foreach ($adjustReasons as $value => $label)
                                <option value="{{ $value }}" wire:key="reason-{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('adjust.category') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="adjust-amount" class="mb-1 block text-xs font-semibold text-ink-strong">Amount</label>
                            <input id="adjust-amount" type="text" inputmode="decimal" maxlength="14" placeholder="0.00" wire:model="adjust.amount" class="form-control !h-[34px] text-right !text-[13px]" @error('adjust.amount') aria-invalid="true" @enderror>
                        </div>
                        <div>
                            <label for="adjust-date" class="mb-1 block text-xs font-semibold text-ink-strong">Memo date</label>
                            <input id="adjust-date" type="date" max="{{ $today }}" wire:model="adjust.issuedOn" class="form-control !h-[34px] !text-[13px]" @error('adjust.issuedOn') aria-invalid="true" @enderror>
                        </div>
                        @error('adjust.amount') <p class="form-error col-span-2" role="alert">{{ $message }}</p> @enderror
                        @error('adjust.issuedOn') <p class="form-error col-span-2" role="alert">{{ $message }}</p> @enderror
                        @if ($adjustType === AdjustmentType::Credit)
                            <p class="col-span-2 text-xs text-label">Up to the unpaid balance of {{ Format::amount($invoice->balance) }}.</p>
                        @endif
                    </div>
                    <div class="sm:col-span-2">
                        <label for="adjust-reason" class="mb-1 block text-xs font-semibold text-ink-strong">Explanation</label>
                        <textarea id="adjust-reason" rows="2" maxlength="500" wire:model="adjust.reason" placeholder="Printed on the memo, e.g. Reading was entered as 150 instead of 105." class="form-control !text-[13px]" @error('adjust.reason') aria-invalid="true" @enderror></textarea>
                        @error('adjust.reason') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="form-actions mt-3">
                    <button type="button" class="text-button" wire:click="closeAdjust">Cancel</button>
                    <button type="submit" class="form-button min-w-[140px]" wire:loading.attr="disabled" wire:target="saveAdjustment">
                        Issue {{ $adjustType?->label() ?? 'Memo' }}
                    </button>
                </div>
            </fieldset>
        </form>
    @endif

    @if ($voiding)
        <form wire:submit="voidInvoice" class="mt-4 rounded-xs border border-danger bg-danger-bg p-3" novalidate>
            <label for="void-invoice-reason" class="block text-xs font-semibold text-danger">Why is this invoice being voided?</label>
            <textarea id="void-invoice-reason" wire:model="voidReason" rows="2" maxlength="255" class="form-control mt-1 !text-[13px]" placeholder="e.g. Wrong reading entered" @error('voidReason') aria-invalid="true" @enderror></textarea>
            @error('voidReason') <p class="form-error" role="alert">{{ $message }}</p> @enderror
            <p class="mt-2 text-xs text-ink-soft">
                Voiding can't be undone. Payments applied to it become advance credit and go to the customer's other bills.
                @if ($reading) Its reading is released so the month can be billed again on Generate Bills. @endif
            </p>
            <div class="form-actions mt-3">
                <button type="button" class="text-button" wire:click="$set('voiding', false)">Back</button>
                <button type="submit" class="form-button min-w-[120px]">Void Invoice</button>
            </div>
        </form>
    @endif

    <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap gap-3">
            <a href="{{ route('invoices.print', $invoice) }}" target="_blank" rel="noopener" class="link-button">Print bill</a>
            <a href="{{ route('customers.statement', $customer) }}" target="_blank" rel="noopener" class="link-button">Statement of account</a>
            @if ($access['edit'] && $invoice->status->isOpen() && ! $adjusting)
                <button type="button" class="link-button" wire:click="openAdjust">Adjust (credit / debit memo)</button>
            @endif
            @if ($access['delete'] && ! $isVoid && ! $voiding)
                <button type="button" class="link-button text-danger" wire:click="askVoid">Void invoice</button>
            @endif
        </div>
        <div class="flex gap-2">
            <button type="button" class="text-button" wire:click="closeModal">Close</button>
            @if ($canRecordPayments && $invoice->status->isOpen())
                <a href="{{ route('payments', ['customer' => $customer->id]) }}" class="form-button inline-flex min-w-[140px] items-center justify-center">Record Payment</a>
            @endif
        </div>
    </div>
</x-modal>
