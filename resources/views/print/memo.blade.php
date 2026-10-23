@use('App\Enums\AdjustmentType')
@use('App\Support\Format')

@php($invoice = $memo->invoice)

<x-print-document :company="$company" :title="($memo->isVoid() ? 'Void ' : '').$memo->type->label()">
    <x-slot:heading>
        <p>No. <span class="font-semibold">{{ $memo->label() }}</span></p>
        <p>Date {{ Format::longDate($memo->issued_on) }}</p>
    </x-slot:heading>

    @if ($memo->isVoid())
        <p class="mt-4 rounded-xs border border-danger bg-danger-bg px-3 py-2 font-semibold text-danger">VOID — {{ $memo->void_reason }}</p>
    @endif

    <section class="mt-5 grid grid-cols-[11rem_1fr] gap-x-4 gap-y-2">
        <p class="text-label">Customer</p>
        <p class="font-semibold text-ink-strong">{{ $memo->customer->first_name }} {{ $memo->customer->last_name }}{{ $memo->customer->company_name ? ' — '.$memo->customer->company_name : '' }}</p>
        <p class="text-label">Account No.</p>
        <p>{{ $memo->customer->account_number }}</p>
        <p class="text-label">Applies to</p>
        <p>Invoice {{ $invoice->invoice_number }} ({{ $invoice->periodLabel() ?? $invoice->kind->label() }}, due {{ Format::longDate($invoice->due_date) }})</p>
        <p class="text-label">Reason</p>
        <p>{{ $memo->reason_category->label() }}</p>
        <p class="text-label">Explanation</p>
        <p class="whitespace-pre-line">{{ $memo->reason }}</p>
        @if ($memo->workOrder)
            <p class="text-label">Work order</p>
            <p>{{ $memo->workOrder->order_number }} ({{ $memo->workOrder->order_type->label() }})</p>
        @endif
        <p class="text-label">{{ $memo->type === AdjustmentType::Credit ? 'Amount credited' : 'Amount charged' }}</p>
        <p class="text-base font-bold text-ink-strong">{{ $company->currency }} {{ Format::amount($memo->amount) }}</p>
        <p class="text-label">Invoice balance now</p>
        <p>{{ Format::amount($invoice->balance) }}</p>
    </section>

    <div class="mt-12 grid grid-cols-2 gap-10">
        <div></div>
        <div class="border-t border-ink pt-1 text-center">
            <p class="font-semibold">{{ $memo->createdBy?->name }}</p>
            <p class="text-xs text-label">Issued by</p>
        </div>
    </div>
</x-print-document>
