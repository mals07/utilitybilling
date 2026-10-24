@props(['title', 'guide' => null])

{{-- Content area of an app page: centered column with the page title, and "Guide me" when the page has a guide (App\Support\Guides). --}}
<div data-page {{ $attributes->class('mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-10 lg:py-8') }}>
    {{-- Inside a setup wizard step, the step's own heading and guide replace this. --}}
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3 in-[.setup-embed]:hidden">
        <h1 class="text-title font-bold text-ink-strong">{{ $title }}</h1>
        @if ($guide)
            <button type="button" class="link-button inline-flex items-center gap-1.5 text-body" x-data x-on:click="$dispatch('guide-start', @js($guide))">
                <x-icon name="document" :size="16" />
                Guide me
            </button>
        @endif
    </div>
    {{ $slot }}
</div>
