{{--
    A labelled input, select or textarea bound to "{$model}.{$key}", e.g. values.orderType.
    $choices ([value => label]) makes it a select, and $disabledChoices ([value => reason]) greys
    some out; $rows makes it a textarea; $live sends each change to the server.
--}}
@php
    $name = "{$model}.{$key}";
    $id = 'work-order-'.str_replace('.', '-', $name);
    $choices ??= null;
    $disabledChoices ??= [];
    $wireModel = ($live ?? false) ? 'wire:model.live' : 'wire:model';
@endphp

<div class="min-w-0">
    <label for="{{ $id }}" class="mb-1 block text-xs font-semibold text-ink-strong">{{ $label }}@if ($required ?? false)<x-required-mark />@endif</label>
    @if ($choices !== null)
        <select
            id="{{ $id }}"
            {{ $wireModel }}="{{ $name }}"
            @if ($required ?? false) aria-required="true" @endif
            class="form-control !h-[34px] !text-[13px]"
            @error($name) aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror
        >
            <option value="">{{ $placeholder ?? '' }}</option>
            @foreach ($choices as $value => $choiceLabel)
                <option value="{{ $value }}" wire:key="{{ $id }}-{{ $value }}" @disabled(isset($disabledChoices[$value]))>
                    {{ $choiceLabel }}{{ isset($disabledChoices[$value]) ? ' ('.$disabledChoices[$value].')' : '' }}
                </option>
            @endforeach
        </select>
    @elseif ($rows ?? null)
        <textarea
            id="{{ $id }}"
            {{ $wireModel }}="{{ $name }}"
            rows="{{ $rows }}"
            placeholder="{{ $placeholder ?? '' }}"
            maxlength="2000"
            @if ($required ?? false) aria-required="true" @endif
            class="form-control !text-[13px]"
            @error($name) aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror
        ></textarea>
    @else
        <input
            id="{{ $id }}"
            type="{{ $type ?? 'text' }}"
            {{ $wireModel }}="{{ $name }}"
            placeholder="{{ $placeholder ?? '' }}"
            maxlength="{{ $maxlength ?? 50 }}"
            autocomplete="off"
            @if ($inputmode ?? null) inputmode="{{ $inputmode }}" @endif
            @if ($max ?? null) max="{{ $max }}" @endif
            @if ($required ?? false) aria-required="true" @endif
            class="form-control !h-[34px] !text-[13px]"
            @error($name) aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror
        >
    @endif
    @error($name)
        <p id="{{ $id }}-error" class="form-error" role="alert">{{ $message }}</p>
    @enderror
    @if (($hint ?? null) && ($choices === null || $choices === []))
        <p class="mt-1 text-xs text-placeholder">{{ $hint }}</p>
    @endif
</div>
