{{--
    One headline figure. $change is the change from the month before (percent, or percentage points when
    $changeUnit is ' pts'); $upIsGood says whether a rise is good news, which picks its color.
--}}
@php
    $change ??= null;
    $changeUnit ??= '%';
    $upIsGood ??= true;
    $note ??= null;
    $isGood = $change !== null && ($change >= 0) === $upIsGood;
@endphp
<div class="flex min-w-0 flex-col gap-1 rounded-xl border border-table-line bg-card p-4 text-right shadow-card">
    <p class="mb-2 text-caption font-semibold tracking-wide text-label uppercase">{{ $label }}</p>
    <p class="truncate text-title font-bold text-ink-strong" title="{{ $value }}"><x-figure :value="$value" decimals="text-heading" /></p>
    @if ($change !== null)
        <p class="text-caption text-label">
            <span @class(['font-semibold', 'text-success' => $isGood, 'text-danger' => ! $isGood])>
                <span aria-hidden="true">{{ $change >= 0 ? '▲' : '▼' }}</span>
                {{ $change >= 0 ? 'Up' : 'Down' }} {{ number_format(abs($change), 1) }}{{ $changeUnit }}
            </span>
            vs {{ $previousLabel }}
        </p>
    @endif
    @if ($note)
        <p class="text-caption text-label">{{ $note }}</p>
    @endif
</div>
