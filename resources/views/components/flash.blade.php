{{--
    A status message that hides itself after a few seconds. Livewire components show one with
    $this->dispatch('flash', message: '…', tone: 'success'|'error'); a redirect can pass one with
    session()->flash('flash', ['message' => '…', 'tone' => '…']).
--}}
@php($initial = session('flash'))

<div
    x-data="{
        flash: @js($initial),
        timer: null,
        show(detail) {
            if (! detail?.message) return;
            this.flash = { message: detail.message, tone: detail.tone ?? 'success', id: Date.now() };
            clearTimeout(this.timer);
            this.timer = setTimeout(() => (this.flash = null), this.flash.tone === 'error' ? 6000 : 4000);
        },
        init() {
            if (this.flash) this.show(this.flash);
        },
    }"
    x-on:flash.window="show($event.detail)"
    wire:ignore
    {{ $attributes }}
>
    <template x-if="flash">
        <div
            x-bind:class="flash.tone === 'error' ? 'alert alert-error' : 'alert alert-success'"
            x-bind:role="flash.tone === 'error' ? 'alert' : 'status'"
            x-text="flash.message"
        ></div>
    </template>
</div>
