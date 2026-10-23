@props(['size' => 32])

@php($gradientId = 'app-logo-'.uniqid())

{{-- The app's water-drop mark, shown when the company has no logo. --}}
<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 32 32" aria-hidden="true" focusable="false" {{ $attributes->merge(['class' => 'shrink-0']) }}>
    <defs>
        <linearGradient id="{{ $gradientId }}" x1="0" y1="0" x2="32" y2="32" gradientUnits="userSpaceOnUse">
            <stop offset="0" stop-color="#0369a1" />
            <stop offset="0.6" stop-color="#0284c7" />
            <stop offset="1" stop-color="#06b6d4" />
        </linearGradient>
    </defs>
    <rect width="32" height="32" rx="9" fill="url(#{{ $gradientId }})" />
    <path d="M16 6c-3.5 4.6-6.5 8.4-6.5 12.1A6.5 6.5 0 0 0 16 24.6a6.5 6.5 0 0 0 6.5-6.5C22.5 14.4 19.5 10.6 16 6Z" fill="#ffffff" />
    <path d="M12.6 18.4a3.6 3.6 0 0 0 2.6 3.3" fill="none" stroke="#7dd3fc" stroke-width="1.4" stroke-linecap="round" />
</svg>
