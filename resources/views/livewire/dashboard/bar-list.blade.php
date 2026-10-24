{{--
    Horizontal bars for a breakdown. Each row: label, amount (sets the bar length), text (shown value),
    and optionally color. Bars are scaled to the largest amount.
--}}
@php
    $largest = max(array_column($rows, 'amount') ?: [0]);
@endphp
@if ($largest <= 0)
    <p class="py-6 text-center text-body text-muted">{{ $empty }}</p>
@else
    <ul class="grid gap-3">
        @foreach ($rows as $row)
            <li wire:key="{{ $listKey }}-{{ $loop->index }}" title="{{ $row['label'] }}: {{ $row['text'] }}">
                <div class="mb-1 flex items-baseline justify-between gap-3 text-body">
                    <span class="truncate text-ink">{{ $row['label'] }}</span>
                    <span class="shrink-0 font-semibold text-ink-strong tabular-nums">{{ $row['text'] }}</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-panel" aria-hidden="true">
                    <div class="h-full rounded-full" style="width: {{ $row['amount'] > 0 ? max(1, round($row['amount'] / $largest * 100, 1)) : 0 }}%; background-color: {{ $row['color'] ?? '#0369a1' }}"></div>
                </div>
            </li>
        @endforeach
    </ul>
@endif
