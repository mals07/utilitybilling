{{-- One report table (App\Support\Reports\ReportTable), on the report page and on its printout. --}}
<section aria-labelledby="{{ $tableId }}-heading" class="min-w-0">
    <h2 id="{{ $tableId }}-heading" class="text-body font-semibold text-ink-strong">{{ $table->title }}</h2>
    @if ($table->note)
        <p class="mt-0.5 text-caption text-label">{{ $table->note }}</p>
    @endif

    <div class="table-scroller mt-2">
        <table class="data-table table-compact">
            <thead>
                <tr>
                    @foreach ($table->columns as $column)
                        <th scope="col" @class(['cell-right' => $column->type->isNumeric()])>{{ $column->label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($table->rows as $row)
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
</section>
