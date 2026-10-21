{{--
    The step-by-step guide (App\Support\Guides): a dimmed page with one part spotlighted and a note
    beside it. Start one with $dispatch('guide-start', 'rates'). The page stays usable underneath,
    so the user can do what the note says.
--}}
<div
    x-data="pageGuide(@js(route('guides.show', '__GUIDE__')))"
    x-on:guide-start.window="start($event.detail)"
    x-on:resize.window="open && place()"
    x-on:keydown.escape.window="if (open && ! document.querySelector('.modal-backdrop')) close()"
>
    <template x-if="open">
        <div>
            <div x-show="spot" class="guide-spot" x-bind:style="spotStyle()" aria-hidden="true"></div>
            <div x-show="! spot" class="guide-dim" aria-hidden="true"></div>

            <div
                x-ref="card"
                role="dialog"
                aria-modal="false"
                aria-labelledby="guide-title"
                class="guide-card"
                x-bind:data-placement="bubble.placement"
                x-bind:style="cardStyle()"
                x-on:pointerenter="hovering = true"
                x-on:pointerleave="hovering = false"
            >
                <div class="flex items-start justify-between gap-3">
                    <p class="text-xs font-medium tracking-wide text-label uppercase">
                        <span x-text="guide.title"></span> &middot; <span x-text="index + 1"></span> of <span x-text="guide.steps.length"></span>
                    </p>
                    <button type="button" class="modal-close" x-on:click="close()" aria-label="Close guide">
                        <x-icon name="close" :size="18" />
                    </button>
                </div>

                <h2 id="guide-title" class="mt-1 text-[15px] font-bold text-ink-strong" x-text="step.title"></h2>
                <p class="mt-1.5 text-sm leading-6 text-ink-soft" x-text="step.body"></p>
                <p x-show="step.grows || step.until" class="mt-2 text-xs text-label italic">This moves on by itself once you've done it.</p>

                <div class="mt-4 flex items-center justify-between gap-3">
                    <button type="button" class="text-button text-sm" x-on:click="close()">Skip guide</button>
                    <div class="flex items-center gap-2">
                        <button type="button" class="form-button form-button-sm bg-transparent text-ink-soft ring-1 ring-line hover:bg-hover" x-show="index > 0" x-on:click="go(-1)">Back</button>
                        <button type="button" class="form-button form-button-sm" x-on:click="go(1)" x-text="last ? 'Done' : 'Next'"></button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
