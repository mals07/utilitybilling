@props([
    'label',
    // Livewire property holding the upload, e.g. "logo".
    'model',
    'accept',
    'help' => [],
    'previewUrl' => null,
    'fileName' => null,
    'error' => null,
    // Livewire action that clears the box.
    'clear',
])

@php
    $inputId = "upload-{$model}";
    $helpId = "{$inputId}-help";
    $errorId = "{$inputId}-error";
@endphp

<div class="flex min-w-0 flex-col gap-1.5">
    <div class="flex items-start justify-between gap-4">
        <div class="flex min-w-0 flex-col gap-2 pt-0.5">
            <label for="{{ $inputId }}" class="text-[13px] font-semibold text-ink">{{ $label }}</label>
            <p id="{{ $helpId }}" class="flex flex-col text-right text-xs leading-[1.55] text-muted italic">
                @foreach ($help as $line)
                    <span>{{ $line }}</span>
                @endforeach
            </p>
        </div>

        <div class="upload-wrap relative flex-none">
            {{-- Decorative click target; the visible label above names the input. --}}
            <label for="{{ $inputId }}" class="upload-box" aria-hidden="true" data-invalid="{{ $error ? 'true' : 'false' }}">
                <span wire:loading wire:target="{{ $model }}" class="text-xs text-muted">Uploading…</span>
                <span wire:loading.remove wire:target="{{ $model }}" class="contents">
                    @if ($previewUrl)
                        <img src="{{ $previewUrl }}" alt="" class="max-h-full max-w-full object-contain">
                    @else
                        <x-icon name="upload" :size="22" />
                    @endif
                </span>
            </label>
            <input
                id="{{ $inputId }}"
                type="file"
                accept="{{ $accept }}"
                wire:model="{{ $model }}"
                class="sr-only"
                @if ($error) aria-invalid="true" aria-describedby="{{ $helpId }} {{ $errorId }}" @else aria-describedby="{{ $helpId }}" @endif
                {{ $attributes }}
            >
            @if ($previewUrl)
                <button type="button" class="upload-remove" wire:click="{{ $clear }}" aria-label="Remove {{ strtolower($label) }}">
                    <span aria-hidden="true">×</span>
                </button>
            @endif
        </div>
    </div>

    @if ($fileName && ! $error)
        <p class="max-w-full self-end truncate text-xs text-muted" title="{{ $fileName }}">{{ $fileName }}</p>
    @endif
    @if ($error)
        <p id="{{ $errorId }}" class="text-xs leading-tight text-danger" role="alert">{{ $error }}</p>
    @endif
</div>
