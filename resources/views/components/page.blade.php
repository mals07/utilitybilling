@props(['title'])

{{-- Content area of an app page: centered column with the page title. --}}
<div {{ $attributes->class('mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-10 lg:py-8') }}>
    <h1 class="mb-4 text-xl font-bold text-ink-strong">{{ $title }}</h1>
    {{ $slot }}
</div>
