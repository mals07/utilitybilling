@props([
    // Accessible name, e.g. "Search rate types".
    'label',
    'width' => 144,
    'size' => 'md',
])

@php($id = 'search-'.md5($label))

<div class="search-box" style="--search-width: {{ $width }}px">
    <label for="{{ $id }}" class="sr-only">{{ $label }}</label>
    <x-icon name="search" :size="14" class="search-icon" />
    <input id="{{ $id }}" type="search" placeholder="Search" data-size="{{ $size }}" class="search-input" {{ $attributes }}>
</div>
