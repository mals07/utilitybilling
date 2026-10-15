{{--
    A labelled input, select or textarea bound to "{$model}.{$key}", e.g. values.orderType.
    $choices ([value => label]) makes it a select; $rows makes it a textarea.
--}}
@php
    $name = "{$model}.{$key}";
    $id = "work-order-{$model}-{$key}";
    $choices ??= null;
@endphp

<div class="min-w-0">
    <label for="{{ $id }}" class="mb-1 block text-xs font-semibold text-ink-strong">{{ $label }}</label>
    @if ($choices !== null)
        <select
            id="{{ $id }}"
            wire:model="{{ $name }}"
            @if ($required ?? false) aria-required="true" @endif
            class="form-control !h-[34px] !text-[13px]"
            @error($name) aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror
        >
            <option value="">{{ $placeholder ?? '' }}</option>
            @foreach ($choices as $value => $choiceLabel)
                <option value="{{ $value }}" wire:key="{{ $id }}-{{ $value }}">{{ $choiceLabel }}</option>
            @endforeach
        </select>
    @elseif ($rows ?? null)
        <textarea
            id="{{ $id }}"
            wire:model="{{ $name }}"
            rows="{{ $rows }}"
            placeholder="{{ $placeholder ?? '' }}"
            maxlength="2000"
            class="form-control !text-[13px]"
            @error($name) aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror
        ></textarea>
    @else
        <input
            id="{{ $id }}"
            type="text"
            wire:model="{{ $name }}"
            placeholder="{{ $placeholder ?? '' }}"
            maxlength="50"
            autocomplete="off"
            class="form-control !h-[34px] !text-[13px]"
            @error($name) aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror
        >
    @endif
    @error($name)
        <p id="{{ $id }}-error" class="form-error" role="alert">{{ $message }}</p>
    @enderror
    @if (($hint ?? null) && $choices === [])
        <p class="mt-1 text-xs text-placeholder">{{ $hint }}</p>
    @endif
</div>
