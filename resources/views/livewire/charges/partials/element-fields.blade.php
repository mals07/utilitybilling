@use('App\Enums\DistributionType')

{{-- Name, "after due" flag and distribution type; used by the add form and the update modal. --}}
<div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
    <div class="min-w-0 flex-1 sm:max-w-80">
        <label for="{{ $prefix }}-name" class="form-label">Charge Element</label>
        <input
            id="{{ $prefix }}-name"
            type="text"
            wire:model="{{ $nameField }}"
            aria-required="true"
            @if ($autofocus ?? false) autofocus @endif
            class="form-control"
            @error($nameField) aria-invalid="true" aria-describedby="{{ $prefix }}-name-error" @enderror
        >
        @error($nameField)
            <p id="{{ $prefix }}-name-error" class="form-error" role="alert">{{ $message }}</p>
        @enderror
        <label class="check mt-2 italic">
            <input type="checkbox" wire:model="{{ $afterDueField }}">
            Is this an after due Charges? (Ex: Surcharge)
        </label>
    </div>

    <fieldset class="min-w-0 border-0 p-0 text-right">
        <legend class="form-legend ml-auto">Distribution Type</legend>
        <div class="mt-2 flex items-center justify-end gap-3">
            @foreach (DistributionType::cases() as $distribution)
                <label class="check">
                    <input type="radio" name="{{ $prefix }}-distribution" value="{{ $distribution->value }}" wire:model="{{ $distributionField }}" class="check-square">
                    {{ $distribution->label() }}
                </label>
            @endforeach
        </div>
        @error($distributionField)
            <p class="form-error" role="alert">{{ $message }}</p>
        @enderror
    </fieldset>
</div>
