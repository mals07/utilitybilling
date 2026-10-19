@use('App\Support\Format')
@use('App\Support\Money')

@php
    $reading = $invoice->meterReading;
    $amountDueCents = ($invoice->status->isOpen() ? Money::toCents($invoice->balance) : 0) + $otherOwedCents;
@endphp

<x-print-document :company="$company" :title="$isVoid ? 'Void Bill' : 'Statement of Bill'">
    <x-slot:heading>
        <p>Invoice No. <span class="font-semibold">{{ $invoice->invoice_number }}</span></p>
        <p>Invoice date {{ $invoice->invoice_date->format('m/d/Y') }}</p>
        <p class="font-semibold">Due date {{ $invoice->due_date->format('m/d/Y') }}</p>
    </x-slot:heading>

    @if ($isVoid)
        <p class="mt-4 rounded-xs border border-danger bg-danger-bg px-3 py-2 font-semibold text-danger">VOID — {{ $invoice->void_reason }}</p>
    @endif

    <section class="mt-4 grid gap-4 sm:grid-cols-2">
        <div>
            <p class="text-xs text-label uppercase">Bill to</p>
            <p class="font-semibold text-ink-strong">{{ $customer->first_name }} {{ $customer->last_name }}</p>
            @if ($customer->company_name)
                <p>{{ $customer->company_name }}</p>
            @endif
            <p>{{ $customer->mailingAddress() }}</p>
        </div>
        <div class="grid grid-cols-2 gap-x-4 gap-y-1">
            <p class="text-label">Account No.</p><p class="font-semibold">{{ $customer->account_number }}</p>
            <p class="text-label">Meter No.</p><p>{{ $reading?->meter_number ?? $customer->meter_number ?? '—' }}</p>
            <p class="text-label">Billing period</p><p>{{ $invoice->periodLabel() ?? $invoice->kind->label() }}</p>
            @if ($invoice->workOrder)
                <p class="text-label">Work order</p><p>{{ $invoice->workOrder->order_number }}</p>
            @endif
        </div>
    </section>

    @if ($reading)
        <table class="data-table table-compact mt-5">
            <thead>
                <tr>
                    <th scope="col">Previous reading</th>
                    <th scope="col">Present reading</th>
                    <th scope="col">Read on</th>
                    <th scope="col" class="cell-right">Consumption (cu.m.)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ Format::quantity($reading->previous_reading) }}</td>
                    <td>{{ $reading->present_reading === null ? 'Defective meter (estimated)' : Format::quantity($reading->present_reading) }}</td>
                    <td>{{ $reading->read_on->format('m/d/Y') }}</td>
                    <td class="cell-right font-semibold">{{ Format::quantity($reading->consumption) }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    <table class="data-table table-compact mt-5">
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
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="cell-right">{{ $line->quantity === null ? '' : Format::quantity($line->quantity) }}</td>
                    <td class="cell-right">{{ $line->rate === null ? '' : Format::amount($line->rate) }}</td>
                    <td class="cell-right">{{ Format::amount($line->amount) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <section class="mt-4 ml-auto grid max-w-sm grid-cols-[1fr_auto] gap-x-6 gap-y-1">
        <p>Current charges</p><p class="text-right">{{ Format::amount($invoice->total_amount) }}</p>
        @if ((float) $invoice->amount_paid > 0)
            <p>Less: payments / advance applied</p><p class="text-right">−{{ Format::amount($invoice->amount_paid) }}</p>
        @endif
        @if ($otherOwedCents > 0)
            <p>Unpaid previous balance</p><p class="text-right">{{ Format::amount(Money::fromCents($otherOwedCents)) }}</p>
        @endif
        <p class="border-t border-line pt-1 text-base font-bold text-ink-strong">Total amount due</p>
        <p class="border-t border-line pt-1 text-right text-base font-bold text-ink-strong">{{ $company->currency }} {{ Format::amount(Money::fromCents($amountDueCents)) }}</p>
        @if ($creditCents > 0)
            <p class="col-span-2 text-xs text-success">Advance credit on account: {{ Format::amount(Money::fromCents($creditCents)) }}</p>
        @endif
    </section>

    <p class="mt-6 text-xs text-label">
        Please pay on or before {{ $invoice->due_date->format('F j, Y') }}. Bills not paid by the due date are subject to the surcharge in your charge type.
        Present this bill when paying.
    </p>
</x-print-document>
