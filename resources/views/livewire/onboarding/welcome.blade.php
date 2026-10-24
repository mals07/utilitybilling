{{--
    A welcome, then one slide per setup wizard step (its screenshot and directions), then a
    closing slide that opens the wizard. Arrow keys move between slides.
--}}
<div
    class="mx-auto max-w-[1500px] px-4 py-6 lg:px-6 lg:py-8"
    x-data="{
        index: 0,
        total: {{ count($slides) + 2 }},
        get last() { return this.index === this.total - 1; },
        go(step) {
            this.index = Math.min(Math.max(this.index + step, 0), this.total - 1);
        },
    }"
    x-on:keydown.right.window="if (! last) go(1)"
    x-on:keydown.left.window="go(-1)"
>
    @if ($justVerified)
        <x-alert tone="success" class="mb-4">Your email address is verified.</x-alert>
    @endif

    <section class="overflow-hidden rounded-xl border border-table-line bg-card shadow-card" aria-live="polite">
        {{-- Welcome --}}
        <div x-show="index === 0" class="px-6 py-10 text-center sm:px-12 sm:py-14">
            <div class="flex justify-center"><x-app-logo :size="56" /></div>
            <h1 class="mt-5 text-2xl font-bold text-ink-strong sm:text-3xl">
                Welcome! Thank you for signing up to Water Utility Management System
            </h1>
            <p class="mx-auto mt-3 max-w-xl text-base leading-7 text-ink-soft">
                Before we start, I will walk you through the steps on how to configure your system.
            </p>

            <ol class="mx-auto mt-8 grid max-w-2xl gap-2 text-left sm:grid-cols-2">
                @foreach ($slides as $slide)
                    <li wire:key="overview-{{ $slide['key'] }}" class="flex items-center gap-3 rounded-lg border border-table-line px-3 py-2 text-sm">
                        <span class="setup-nav-mark shrink-0">{{ $slide['number'] }}</span>
                        <span class="min-w-0 flex-1 truncate font-medium text-ink-strong">{{ $slide['title'] }}</span>
                        <span class="shrink-0 text-xs {{ $slide['required'] ? 'text-ink-soft' : 'text-label' }}">{{ $slide['required'] ? 'Required' : 'Optional' }}</span>
                    </li>
                @endforeach
            </ol>

            <p class="mx-auto mt-6 max-w-xl text-sm text-label">
                Steps 1–{{ $requiredCount }} are required before you can use the system. It takes about a minute to look through.
            </p>
        </div>

        {{-- One slide per setup step --}}
        @foreach ($slides as $i => $slide)
            <div wire:key="slide-{{ $slide['key'] }}" x-show="index === {{ $i + 1 }}" x-cloak class="grid xl:grid-cols-[5fr_2fr]">
                <div class="border-b border-table-line bg-panel xl:border-r xl:border-b-0">
                    @if ($slide['image'])
                        <a href="{{ $slide['image'] }}" target="_blank" rel="noopener" title="Open full size">
                            <img src="{{ $slide['image'] }}" alt="Screenshot of the {{ $slide['title'] }} step" class="block h-auto w-full" loading="lazy">
                        </a>
                    @else
                        <div class="flex aspect-video items-center justify-center text-sm text-label">Screenshot coming soon</div>
                    @endif
                </div>

                <div class="p-6 sm:p-10">
                    <p class="text-sm font-medium uppercase tracking-wide text-label">
                        Step {{ $slide['number'] }} of {{ count($slides) }} &middot; {{ $slide['required'] ? 'Required' : 'Optional' }}
                    </p>
                    <h2 class="mt-1 text-2xl font-bold text-ink-strong">{{ $slide['title'] }}</h2>
                    <p class="mt-3 text-base leading-7 text-ink-soft">{{ $slide['intro'] }}</p>

                    <h3 class="mt-6 text-base font-semibold text-ink-strong">What to do</h3>
                    <ol class="mt-2 list-decimal space-y-3 pl-5 text-base leading-7 text-ink-soft">
                        @foreach ($slide['howTo'] as $direction)
                            <li>{{ $direction }}</li>
                        @endforeach
                    </ol>
                </div>
            </div>
        @endforeach

        {{-- Ready --}}
        <div x-show="last" x-cloak class="px-6 py-12 text-center sm:px-12 sm:py-16">
            <span class="inline-flex size-14 items-center justify-center rounded-full bg-success text-white"><x-icon name="check" :size="28" /></span>
            <h2 class="mt-5 text-2xl font-bold text-ink-strong">You're ready to set up your system</h2>
            <p class="mx-auto mt-3 max-w-xl text-base leading-7 text-ink-soft">
                Each step has a <strong>"Show me how"</strong> guide and an example you can fill in with one click.
                Click <strong>Finish later</strong> at any time; you'll continue where you left off when you sign in again.
            </p>
            <button type="button" class="form-button mt-8" wire:click="start" wire:loading.attr="disabled" wire:target="start">
                Start setup
            </button>
        </div>

        {{-- Progress and navigation --}}
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-table-line px-6 py-4">
            <button type="button" class="text-button text-sm" x-show="! last" wire:click="start" wire:loading.attr="disabled" wire:target="start">
                Skip and start setup
            </button>
            <span x-show="last"></span>

            <div class="order-last flex w-full gap-1 sm:order-none sm:w-48" aria-hidden="true">
                <template x-for="i in total" x-bind:key="i">
                    <span class="h-1 flex-1 rounded-full" x-bind:class="i - 1 <= index ? 'bg-button' : 'bg-panel'"></span>
                </template>
            </div>

            <div class="flex items-center gap-2">
                <button type="button" class="form-button form-button-sm bg-transparent text-ink-soft ring-1 ring-line hover:bg-hover" x-show="index > 0" x-on:click="go(-1)">Back</button>
                <button type="button" class="form-button form-button-sm" x-show="! last" x-on:click="go(1)">
                    <span x-text="index === 0 ? 'Show me the steps' : 'Next'"></span>
                </button>
            </div>
        </div>
    </section>
</div>
