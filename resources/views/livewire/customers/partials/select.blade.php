@php($id = "customer-{$key}")

<div class="min-w-0">
    <label for="{{ $id }}" class="mb-1 block text-xs font-medium text-ink">{{ $label }}@if ($required ?? true)<x-required-mark />@endif</label>
    <select
        id="{{ $id }}"
        wire:model{{ ($live ?? false) ? '.live' : '' }}="values.{{ $key }}"
        @if ($required ?? true) aria-required="true" @endif
        class="form-control !h-[34px] !text-[13px]"
        @error("values.{$key}") aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror
    >
        <option value="">{{ $placeholder }}</option>
        @foreach ($choices as $value => $choiceLabel)
            <option value="{{ $value }}" wire:key="{{ $id }}-{{ $value }}">{{ $choiceLabel }}</option>
        @endforeach
    </select>
    @error("values.{$key}")
        <p id="{{ $id }}-error" class="form-error" role="alert">{{ $message }}</p>
    @enderror
    @if (($hint ?? null) && $choices === [])
        <p class="mt-1 text-xs text-placeholder">{{ $hint }}</p>
    @endif
</div>
