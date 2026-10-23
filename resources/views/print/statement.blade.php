@use('App\Support\Format')
@use('App\Support\Money')

@php($net = $summary['owed'] - $summary['credit'])

<x-print-document :company="$company" title="Statement of Account">
    <x-slot:heading>
        <p>As of {{ Format::date(now(), $company->timezone) }}</p>
    </x-slot:heading>

    <section class="mt-4 grid gap-4 sm:grid-cols-2">
        <div>
            <p class="font-semibold text-ink-strong">{{ $customer->first_name }} {{ $customer->last_name }}</p>
            @if ($customer->company_name)
                <p>{{ $customer->company_name }}</p>
            @endif
            <p>{{ $customer->mailingAddress() }}</p>
        </div>
        <div class="grid grid-cols-2 gap-x-4 gap-y-1">
            <p class="text-label">Account No.</p><p class="font-semibold">{{ $customer->account_number }}</p>
            <p class="text-label">Meter No.</p><p>{{ $customer->meter_number ?? '—' }}</p>
            <p class="text-label">Status</p><p>{{ $customer->status->label() }}</p>
        </div>
    </section>

    <table class="data-table table-compact mt-5">
        <thead>
            <tr>
                <th scope="col">Date</th>
                <th scope="col">Reference</th>
                <th scope="col">Description</th>
                <th scope="col" class="cell-right">Charges</th>
                <th scope="col" class="cell-right">Payments / Credits</th>
                <th scope="col" class="cell-right">Balance</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($entries as $entry)
                <tr>
                    <td class="whitespace-nowrap">{{ Format::isoDate($entry['date']) }}</td>
                    <td class="whitespace-nowrap">{{ $entry['reference'] }}</td>
                    <td>{{ $entry['description'] }}{{ $entry['due'] ? ' (due '.$entry['due'].')' : '' }}</td>
                    <td class="cell-right">{{ $entry['charge'] ? Format::amount(Money::fromCents($entry['charge'])) : '' }}</td>
                    <td class="cell-right">{{ $entry['payment'] ? Format::amount(Money::fromCents($entry['payment'])) : '' }}</td>
                    <td class="cell-right">{{ Format::amount(Money::fromCents($entry['balance'])) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-label">No bills or payments yet.</td></tr>
            @endforelse
        </tbody>
    </table>

    <section class="mt-4 ml-auto grid max-w-sm grid-cols-[1fr_auto] gap-x-6 gap-y-1">
        <p>Unpaid bills</p><p class="text-right">{{ Format::amount(Money::fromCents($summary['owed'])) }}</p>
        <p>Advance credit</p><p class="text-right">{{ Format::amount(Money::fromCents($summary['credit'])) }}</p>
        <p class="border-t border-line pt-1 text-base font-bold text-ink-strong">{{ $net >= 0 ? 'Balance due' : 'Credit balance' }}</p>
        <p class="border-t border-line pt-1 text-right text-base font-bold text-ink-strong">{{ $company->currency }} {{ Format::amount(Money::fromCents(abs($net))) }}</p>
    </section>
</x-print-document>
