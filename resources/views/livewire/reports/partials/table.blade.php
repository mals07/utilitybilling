@use('App\Livewire\Reports\ReportPage')
@use('App\Support\Format')

{{--
    One report table (App\Support\Reports\ReportTable), on the report page and on its printout. On the page
    ($paginator given) the column headings sort the rows, and it shows one page of rows with a pager and a
    rows-per-page choice; the totals row always covers every row. Printouts get every row, unsorted.
--}}
@php
    $paginator ??= null;
    $sort ??= null;
    $sortable = $paginator !== null;
    $rows = $paginator?->items() ?? $table->rows;
    $pageable = $paginator !== null && $paginator->total() > (int) ReportPage::ROWS_PER_PAGE[0];
@endphp
<section aria-labelledby="{{ $tableId }}-heading" class="min-w-0">
    <h2 id="{{ $tableId }}-heading" class="text-body font-semibold text-ink-strong">{{ $table->title }}</h2>
    @if ($table->note)
        <p class="mt-0.5 text-caption text-label">{{ $table->note }}</p>
    @endif

    <div class="table-scroller mt-2">
        <table class="data-table table-compact">
            <thead>
                <tr>
                    @foreach ($table->columns as $index => $column)
                        @php($sortedHere = $sort !== null && $sort['column'] === $index)
                        <th
                            scope="col"
                            @class(['cell-right' => $column->type->isNumeric()])
                            @if ($sortable) aria-sort="{{ $sortedHere ? ($sort['direction'] === 'asc' ? 'ascending' : 'descending') : 'none' }}" @endif
                        >
                            @if ($sortable && count($table->rows) > 1)
                                <button
                                    type="button"
                                    wire:click="sortBy(@js($paginator->getPageName()), {{ $index }})"
                                    @class(['inline-flex items-center gap-1 uppercase hover:text-ink-strong', 'flex-row-reverse' => $column->type->isNumeric(), 'text-ink-strong' => $sortedHere])
                                    title="Sort by {{ $column->label }}"
                                >
                                    {{ $column->label }}
                                    <span aria-hidden="true" @class(['text-[10px]', 'opacity-30' => ! $sortedHere])>{{ $sortedHere && $sort['direction'] === 'desc' ? '▼' : ($sortedHere ? '▲' : '↕') }}</span>
                                </button>
                            @else
                                {{ $column->label }}
                            @endif
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        @foreach ($table->columns as $index => $column)
                            <td @class([
                                'cell-right whitespace-nowrap' => $column->type->isNumeric(),
                                'whitespace-nowrap' => ! $column->type->isNumeric() && ! $column->wide,
                                'min-w-40' => $column->wide,
                                'font-medium text-ink-strong' => $index === 0,
                            ])>{{ $column->type->format($row[$index] ?? null) }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($table->columns) }}" class="py-6 text-center text-label">{{ $table->emptyText }}</td>
                    </tr>
                @endforelse
            </tbody>
            @if ($table->totals !== null && $table->rows !== [])
                <tfoot>
                    <tr class="border-t-2 border-table-line bg-panel font-semibold text-ink-strong">
                        @foreach ($table->columns as $index => $column)
                            <td @class(['cell-right whitespace-nowrap' => $column->type->isNumeric(), 'whitespace-nowrap' => ! $column->type->isNumeric()])>
                                {{ $table->totals[$index] === null ? '' : $column->type->format($table->totals[$index]) }}
                            </td>
                        @endforeach
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    @if ($pageable)
        <div class="mt-2 flex flex-wrap items-center justify-between gap-3 text-caption text-label">
            <div class="flex flex-wrap items-center gap-3">
                <p>Showing {{ Format::integer($paginator->firstItem()) }}–{{ Format::integer($paginator->lastItem()) }} of {{ Format::integer($paginator->total()) }}</p>
                <label for="{{ $tableId }}-rows" class="flex items-center gap-1.5">
                    Rows per page
                    <select
                        id="{{ $tableId }}-rows"
                        wire:change="setRowsPerPage(@js($paginator->getPageName()), $event.target.value)"
                        class="form-control form-control-sm w-20 !text-body"
                    >
                        @foreach (ReportPage::ROWS_PER_PAGE as $choice)
                            <option value="{{ $choice }}" @selected($rowsPerPage === $choice)>{{ $choice === 'all' ? 'All' : $choice }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <x-pagination :paginator="$paginator" />
        </div>
    @endif
</section>
