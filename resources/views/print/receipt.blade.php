@use('App\Support\Format')

<x-print-document :company="$company" :title="$payment->isVoid() ? 'Void Receipt' : 'Official Receipt'">
    <x-slot:heading>
        <p>OR No. <span class="font-semibold">{{ $payment->receipt_number }}</span></p>
        <p>Date {{ Format::longDate($payment->paid_on) }}</p>
    </x-slot:heading>

    @if ($payment->isVoid())
        <p class="mt-4 rounded-xs border border-danger bg-danger-bg px-3 py-2 font-semibold text-danger">VOID — {{ $payment->void_reason }}</p>
    @endif

    <section class="mt-5 grid grid-cols-[10rem_1fr] gap-x-4 gap-y-2">
        <p class="text-label">Received from</p>
        <p class="font-semibold text-ink-strong">{{ $payment->customer->first_name }} {{ $payment->customer->last_name }}{{ $payment->customer->company_name ? ' — '.$payment->customer->company_name : '' }}</p>
        <p class="text-label">Account No.</p>
        <p>{{ $payment->customer->account_number }}</p>
        <p class="text-label">Amount</p>
        <p class="text-base font-bold text-ink-strong">{{ $company->currency }} {{ Format::amount($payment->amount) }}</p>
        <p class="text-label">Paid by</p>
        <p>{{ $payment->method->label() }}{{ $payment->reference_number ? ' · Ref. '.$payment->reference_number : '' }}</p>
        @if ($payment->notes)
            <p class="text-label">Notes</p>
            <p>{{ $payment->notes }}</p>
        @endif
    </section>

    <table class="data-table table-compact mt-5">
        <thead>
            <tr>
                <th scope="col">In payment of</th>
                <th scope="col">Billing period</th>
                <th scope="col" class="cell-right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($payment->allocations as $allocation)
                <tr>
                    <td>Invoice {{ $allocation->invoice->invoice_number }}</td>
                    <td>{{ $allocation->invoice->periodLabel() ?? $allocation->invoice->kind->label() }}</td>
                    <td class="cell-right">{{ Format::amount($allocation->amount) }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="text-label">Advance payment — applied to the next bill.</td></tr>
            @endforelse
            @if ((float) $payment->unapplied_amount > 0 && $payment->allocations->isNotEmpty())
                <tr>
                    <td colspan="2">Advance credit (applied to the next bill)</td>
                    <td class="cell-right">{{ Format::amount($payment->unapplied_amount) }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <div class="mt-12 grid grid-cols-2 gap-10">
        <div></div>
        <div class="border-t border-ink pt-1 text-center">
            <p class="font-semibold">{{ $payment->receivedBy?->name }}</p>
            <p class="text-xs text-label">Received by</p>
        </div>
    </div>
</x-print-document>
