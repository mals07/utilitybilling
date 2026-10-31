<?php

namespace App\Support\Reports;

/**
 * A table of a report: its columns, rows and an optional totals row. Rows hold raw values that the
 * columns' types format (see ReportColumnType).
 */
final readonly class ReportTable
{
    /**
     * @param  list<ReportColumn>  $columns
     * @param  list<list<mixed>>  $rows  One value per column.
     * @param  list<mixed>|null  $totals  One value per column; null for none.
     */
    public function __construct(
        public string $title,
        public array $columns,
        public array $rows,
        public ?array $totals = null,
        public string $emptyText = 'Nothing to show.',
        public ?string $note = null,
    ) {}

    /**
     * Sums the given columns of the rows, with "Total" in the first column and nothing in the others.
     *
     * @param  list<list<mixed>>  $rows
     * @param  list<int>  $sumColumns  Indexes of the columns to add up.
     * @return list<mixed>
     */
    public static function totalsOf(array $rows, int $columnCount, array $sumColumns, string $label = 'Total'): array
    {
        $totals = array_fill(0, $columnCount, null);
        $totals[0] = $label;
        foreach ($sumColumns as $index) {
            $totals[$index] = array_sum(array_map(fn (array $row): int|float => $row[$index] ?? 0, $rows));
        }

        return $totals;
    }
}
