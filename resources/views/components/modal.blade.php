@props([
    'title',
    // JavaScript run to close the modal, e.g. "$wire.closeModal()".
    'close',
    // sm 384px, md 448px, form 640px, lg 768px, xl 880px.
    'width' => 'md',
])

@php($titleId = 'modal-'.md5($title.$close))

{{--
    Rendered only while open. Focus stays inside; Escape and a click on the backdrop close it.
    Escape closes only the topmost modal, wherever focus is, like a native <dialog>; controls
    inside that handle Escape themselves stop it first.
--}}
<div
    class="modal-backdrop"
    x-data
    x-on:click.self="{{ $close }}"
    x-on:keydown.escape.window="
        if ($event.defaultPrevented || [...document.querySelectorAll('.modal-backdrop')].at(-1) !== $el) return;
        $event.preventDefault();
        {{ $close }};
    "
>
    <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="{{ $titleId }}"
        class="modal-dialog"
        data-width="{{ $width }}"
        x-trap.noscroll="true"
    >
        <div class="modal-content">
            <div class="flex items-start justify-between gap-3">
                <h2 id="{{ $titleId }}" class="text-heading font-bold text-ink-strong">{{ $title }}</h2>
                <button type="button" class="modal-close" x-on:click="{{ $close }}" aria-label="Close">
                    <x-icon name="close" />
                </button>
            </div>

            {{ $slot }}
        </div>
    </div>
</div>
