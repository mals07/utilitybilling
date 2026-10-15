@props(['size' => 32])

{{-- The app's water-drop mark, shown when the company has no logo. --}}
<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 32 32" aria-hidden="true" focusable="false" {{ $attributes->merge(['class' => 'shrink-0']) }}>
    <rect width="32" height="32" rx="4" fill="#374151" />
    <path d="M16 6c-3.5 4.6-6.5 8.4-6.5 12.1A6.5 6.5 0 0 0 16 24.6a6.5 6.5 0 0 0 6.5-6.5C22.5 14.4 19.5 10.6 16 6Z" fill="#ffffff" />
    <path d="m17.2 12.5-3.4 5.2h2.6l-1.2 4.1 3.6-5.6h-2.7l1.1-3.7Z" fill="#374151" />
</svg>
