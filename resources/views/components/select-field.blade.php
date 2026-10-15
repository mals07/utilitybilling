@props([
    'label',
    'id',
    'placeholder',
    // [value => label]
    'options' => [],
    'error' => null,
])

@php($errorId = "{$id}-error")

<div class="field">
    <label for="{{ $id }}" class="field-label">{{ $label }}</label>
    <select
        id="{{ $id }}"
        class="field-control"
        @if ($error) aria-invalid="true" aria-describedby="{{ $errorId }}" @endif
        {{ $attributes }}
    >
        <option value="">{{ $placeholder }}</option>
        @foreach ($options as $value => $optionLabel)
            <option value="{{ $value }}" wire:key="{{ $id }}-{{ $value }}">{{ $optionLabel }}</option>
        @endforeach
    </select>

    @if ($error)
        <p id="{{ $errorId }}" class="field-error" role="alert">{{ $error }}</p>
    @endif
</div>
