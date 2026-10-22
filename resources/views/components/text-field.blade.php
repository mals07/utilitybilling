@props([
    'label',
    'id',
    'error' => null,
    // "inset" puts the label inside the bordered box (Point Person fields).
    'variant' => 'default',
    'type' => 'text',
    'required' => false,
])

@php($errorId = "{$id}-error")

<div class="field">
    @if ($variant === 'inset')
        <div class="inset">
            <label for="{{ $id }}" class="inset-label">{{ $label }}@if ($required)<x-required-mark />@endif</label>
            <input
                id="{{ $id }}"
                type="{{ $type }}"
                class="inset-control"
                @if ($required) aria-required="true" @endif
                @if ($error) aria-invalid="true" aria-describedby="{{ $errorId }}" @endif
                {{ $attributes }}
            >
        </div>
    @else
        <label for="{{ $id }}" class="field-label">{{ $label }}@if ($required)<x-required-mark />@endif</label>
        <input
            id="{{ $id }}"
            type="{{ $type }}"
            class="field-control"
            @if ($required) aria-required="true" @endif
            @if ($error) aria-invalid="true" aria-describedby="{{ $errorId }}" @endif
            {{ $attributes }}
        >
    @endif

    @if ($error)
        <p id="{{ $errorId }}" class="field-error" role="alert">{{ $error }}</p>
    @endif
</div>
