{{--
    A card at the bottom of the screen that steps through the tour while the matching sidebar
    link (marked data-tour) is outlined. Arrow keys move between stops; Escape skips the tour.
--}}
<div
    x-data="{
        stops: @js($stops),
        index: 0,
        open: false,
        get stop() { return this.stops[this.index]; },
        get last() { return this.index === this.stops.length - 1; },
        start() {
            this.index = 0;
            this.open = true;
            this.highlight();
        },
        go(step) {
            this.index = Math.min(Math.max(this.index + step, 0), this.stops.length - 1);
            this.highlight();
        },
        finish() {
            this.open = false;
            this.highlight();
            $wire.complete();
        },
        highlight() {
            document.querySelectorAll('[data-tour-active]').forEach((el) => el.removeAttribute('data-tour-active'));
            if (! this.open || ! this.stop.target) return;
            document.querySelectorAll(`[data-tour='${this.stop.target}']`).forEach((el) => el.setAttribute('data-tour-active', ''));
        },
    }"
    x-init="@if ($autoStart) $nextTick(() => start()) @endif"
    x-on:start-tour.window="start()"
    x-on:keydown.escape.window="if (open && ! document.querySelector('.modal-backdrop')) finish()"
    x-on:keydown.right.window="if (open && ! last) go(1)"
    x-on:keydown.left.window="if (open) go(-1)"
>
    <div
        x-show="open"
        x-cloak
        role="dialog"
        aria-modal="false"
        aria-labelledby="tour-title"
        class="tour-card"
    >
        <template x-if="stop.image">
            <img x-bind:src="stop.image" x-bind:alt="`Screenshot of ${stop.title}`" class="tour-image">
        </template>

        <div class="p-5">
            <div class="flex items-start justify-between gap-3">
                <p class="text-caption font-medium uppercase tracking-wide text-label">
                    Step <span x-text="index + 1"></span> of <span x-text="stops.length"></span>
                </p>
                <button type="button" class="modal-close" x-on:click="finish()" aria-label="Close tour">
                    <x-icon name="close" />
                </button>
            </div>

            <h2 id="tour-title" class="mt-1 text-heading font-bold text-ink-strong" x-text="stop.title"></h2>
            <p class="mt-2 text-body leading-6 text-ink-soft" x-text="stop.body"></p>

            <div class="mt-3 flex gap-1" aria-hidden="true">
                <template x-for="(item, i) in stops" x-bind:key="item.key">
                    <span class="h-1 flex-1 rounded-full" x-bind:class="i <= index ? 'bg-button' : 'bg-panel'"></span>
                </template>
            </div>

            <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-4">
                    <button type="button" class="text-button text-body" x-show="! last" x-on:click="finish()">Skip tour</button>
                    <a x-show="stop.url && stop.key !== 'dashboard'" x-bind:href="stop.url" class="link-button text-body" x-on:click="finish()">Open page</a>
                </div>

                <div class="flex items-center gap-2">
                    <button type="button" class="form-button form-button-sm bg-transparent text-ink-soft ring-1 ring-line hover:bg-hover" x-show="index > 0" x-on:click="go(-1)">Back</button>
                    <button type="button" class="form-button form-button-sm" x-show="! last" x-on:click="go(1)">Next</button>
                    <button type="button" class="form-button form-button-sm" x-show="last" x-on:click="finish()">Finish</button>
                </div>
            </div>
        </div>
    </div>
</div>
