@use('App\Support\Format')

{{-- Notices of disconnection, one per printed page. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Notices of Disconnection | {{ $company->company_name }}</title>
        @vite(['resources/css/app.css'])
        <style>
            @page { size: A4; margin: 14mm; }
            .print-sheet + .print-sheet { break-before: page; }
            @media print {
                .no-print { display: none !important; }
                body { background: #fff; }
                .print-sheet { box-shadow: none; border: 0; padding: 0; margin: 0; max-width: none; }
            }
        </style>
    </head>
    <body class="bg-panel font-sans text-ink">
        <div class="no-print mx-auto flex max-w-[800px] items-center justify-between gap-3 px-4 py-3">
            <button type="button" onclick="history.length > 1 ? history.back() : window.close()" class="link-button">← Back</button>
            <p class="text-[13px] text-label">{{ $notices->count() }} {{ Str::plural('notice', $notices->count()) }}</p>
            <button type="button" onclick="window.print()" class="form-button form-button-sm min-w-32">Print / Save as PDF</button>
        </div>

        @foreach ($notices as $notice)
            @php($customer = $notice->customer)
            @php($bills = $overdueBills[$notice->id])
            <main class="print-sheet mx-auto mb-8 max-w-[800px] border border-table-line bg-card px-8 py-8 text-[13px]">
                <x-print-letterhead :company="$company" title="Notice of Disconnection">
                    <p>No. <span class="font-semibold">{{ $notice->notice_number }}</span></p>
                    <p>Date {{ Format::longDate($notice->issued_on) }}</p>
                </x-print-letterhead>

                <section class="mt-5 grid grid-cols-[9rem_1fr] gap-x-4 gap-y-1">
                    <p class="text-label">Customer</p>
                    <p class="font-semibold text-ink-strong">{{ $customer->fullName() }}{{ $customer->company_name ? ' — '.$customer->company_name : '' }}</p>
                    <p class="text-label">Account No.</p>
                    <p>{{ $customer->account_number }}</p>
                    <p class="text-label">Address</p>
                    <p>{{ $customer->mailingAddress() }}</p>
                    @if ($customer->meter_number)
                        <p class="text-label">Meter No.</p>
                        <p>{{ $customer->meter_number }}</p>
                    @endif
                    @if ($customer->clusterType)
                        <p class="text-label">Cluster</p>
                        <p>{{ $customer->clusterType->name }}</p>
                    @endif
                </section>

                <p class="mt-5">Dear {{ $customer->fullName() }},</p>
                <p class="mt-2">
                    Our records show the following bills on your account remain unpaid past their due date.
                    Please settle the overdue amount on or before
                    <span class="font-bold text-ink-strong">{{ Format::longDate($notice->deadline) }}</span>
                    to avoid disconnection of your water service.
                </p>

                <table class="data-table table-compact mt-4">
                    <thead>
                        <tr>
                            <th scope="col">Invoice</th>
                            <th scope="col">Billing</th>
                            <th scope="col">Due date</th>
                            <th scope="col" class="cell-right">Unpaid</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($bills as $bill)
                            <tr>
                                <td>{{ $bill->invoice_number }}</td>
                                <td>{{ $bill->periodLabel() ?? $bill->kind->label() }}</td>
                                <td>{{ Format::longDate($bill->due_date) }}</td>
                                <td class="cell-right">{{ Format::amount($bill->balance) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-label">No overdue bills now. This notice no longer applies.</td></tr>
                        @endforelse
                    </tbody>
                    @if ($bills->isNotEmpty())
                        <tfoot>
                            <tr>
                                <td colspan="3" class="font-semibold">Total overdue</td>
                                <td class="cell-right text-base font-bold text-ink-strong">{{ $company->currency }} {{ Format::amount($bills->sum('balance')) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>

                <p class="mt-4">
                    Once disconnected, service will only be restored after the overdue bills and the reconnection fee are paid.
                    If you have already paid, please disregard this notice. For questions, please visit our office{{ $company->phone_number ? ' or call '.$company->phone_number : '' }}.
                </p>

                <div class="mt-12 grid grid-cols-2 gap-10">
                    <div class="border-t border-ink pt-1 text-center">
                        <p class="text-xs text-label">Received by (name, signature and date)</p>
                    </div>
                    <div class="border-t border-ink pt-1 text-center">
                        <p class="font-semibold">{{ $company->head_of_company }}</p>
                        <p class="text-xs text-label">{{ $company->company_name }}</p>
                    </div>
                </div>

                <footer class="mt-8 border-t border-line pt-3 text-center text-xs text-label">
                    @foreach ([$company->billing_footer_1, $company->billing_footer_2, $company->billing_footer_3] as $line)
                        @if ($line)
                            <p>{{ $line }}</p>
                        @endif
                    @endforeach
                </footer>
            </main>
        @endforeach
    </body>
</html>
