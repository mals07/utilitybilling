<x-print-document :company="$company" :title="$report->title()" landscape>
    <x-slot:heading>
        <p>{{ $report->subtitle() }}</p>
        @if ($report->filterLabel())
            <p>{{ $report->filterLabel() }}</p>
        @endif
    </x-slot:heading>

    @php($summary = $report->summary())
    @if ($summary !== [])
        <section class="mt-4 grid grid-cols-4 gap-3">
            @foreach ($summary as $figure)
                <div class="rounded-xs border border-table-line px-3 py-2">
                    <p class="text-label">{{ $figure['label'] }}</p>
                    <p class="text-base font-bold text-ink-strong">{{ $figure['value'] }}</p>
                </div>
            @endforeach
        </section>
    @endif

    <div class="mt-5 grid gap-5 [&_.table-scroller]:overflow-visible [&_.table-scroller]:rounded-none [&_.table-scroller]:shadow-none">
        @foreach ($report->tables() as $table)
            <div>
                @include('livewire.reports.partials.table', ['table' => $table, 'tableId' => 'report-table-'.$loop->index])
            </div>
        @endforeach
    </div>

    @if ($report->note())
        <p class="mt-4 text-label">{{ $report->note() }}</p>
    @endif
</x-print-document>
