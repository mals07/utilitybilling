@php($id = "customer-{$key}")

<div class="min-w-0">
    <label for="{{ $id }}" class="mb-1 block text-xs font-medium text-ink">{{ $label }}@if ($required ?? false)<x-required-mark />@endif</label>
    <input
        id="{{ $id }}"
        type="{{ $type ?? 'text' }}"
        wire:model="values.{{ $key }}"
        placeholder="{{ $placeholder }}"
        autocomplete="off"
        @if ($autofocus ?? false) autofocus @endif
        @if ($inputmode ?? null) inputmode="{{ $inputmode }}" @endif
        @if ($required ?? false) aria-required="true" @endif
        class="form-control !h-[34px] !text-[13px]"
        @error("values.{$key}") aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror
    >
    @error("values.{$key}")
        <p id="{{ $id }}-error" class="form-error" role="alert">{{ $message }}</p>
    @enderror
</div>
