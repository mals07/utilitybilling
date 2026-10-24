@props(['value', 'decimals'])

{{-- A figure such as "PHP 1,234.50" or "87.5%" with its decimal part one step smaller on the type scale ($decimals). --}}
@php
    $hasDecimals = preg_match('/^(.*?\d)(\.\d+)(.*)$/s', $value, $parts) === 1;
@endphp
@if ($hasDecimals)
<span {{ $attributes }}>{{ $parts[1] }}<span class="{{ $decimals }}">{{ $parts[2] }}</span>{{ $parts[3] }}</span>
@else
<span {{ $attributes }}>{{ $value }}</span>
@endif
