@props([
    // Rows already in the table.
    'count',
    // Rows drawn in total, so empty tables keep their shape.
    'minimum',
    'columns',
    // Shown in the first filler row when the table is empty.
    'emptyText' => null,
    // Column (0-based) that holds the empty text.
    'textColumn' => 1,
])

@for ($row = 0; $row < max($minimum - $count, 0); $row++)
    <tr aria-hidden="true" wire:key="filler-{{ $row }}">
        @for ($column = 0; $column < $columns; $column++)
            <td class="cell-empty">{{ $row === 0 && $count === 0 && $column === $textColumn ? $emptyText : '' }}</td>
        @endfor
    </tr>
@endfor
