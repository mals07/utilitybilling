@props(['paginator'])

@php
    $page = $paginator->currentPage();
    $lastPage = $paginator->lastPage();
    $pageName = $paginator->getPageName();

    // First, last, and two either side of the current page.
    $window = [];
    for ($n = 1; $n <= $lastPage; $n++) {
        if ($n === 1 || $n === $lastPage || abs($n - $page) <= 2) {
            $window[] = $n;
        } elseif (end($window) !== 'gap') {
            $window[] = 'gap';
        }
    }
@endphp

@if ($lastPage > 1)
    <nav aria-label="Pagination" class="pager">
        @if ($page > 1)
            <button type="button" wire:click="setPage({{ $page - 1 }}, '{{ $pageName }}')" class="pager-item" aria-label="Previous page">&lsaquo;</button>
        @else
            <span class="pager-disabled" aria-hidden="true">&lsaquo;</span>
        @endif

        @foreach ($window as $index => $n)
            @if ($n === 'gap')
                <span wire:key="gap-{{ $index }}" class="pager-gap" aria-hidden="true">&bull;&bull;&bull;</span>
            @elseif ($n === $page)
                <span wire:key="page-{{ $n }}" class="pager-current" aria-current="page">{{ $n }}</span>
            @else
                <button type="button" wire:key="page-{{ $n }}" wire:click="setPage({{ $n }}, '{{ $pageName }}')" class="pager-item" aria-label="Go to page {{ $n }}">{{ $n }}</button>
            @endif
        @endforeach

        @if ($page < $lastPage)
            <button type="button" wire:click="setPage({{ $page + 1 }}, '{{ $pageName }}')" class="pager-item" aria-label="Next page">&rsaquo;</button>
        @else
            <span class="pager-disabled" aria-hidden="true">&rsaquo;</span>
        @endif
    </nav>
@endif
