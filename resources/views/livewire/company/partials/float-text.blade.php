<div>
    <label class="float">
        <span class="float-label">{{ $label }}</span>
        <input
            type="text"
            wire:model="{{ $field }}"
            placeholder=" "
            @disabled(! $canEdit)
            class="float-control"
            @if ($error) aria-invalid="true" aria-describedby="ops-{{ $field }}-error" @endif
        >
    </label>
    @if ($error)
        <p id="ops-{{ $field }}-error" class="form-error" role="alert">{{ $error }}</p>
    @endif
</div>
