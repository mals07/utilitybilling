{{-- $prefix: "home" or "billing"; $locations: the city, province and country choices. --}}
@php($required = $prefix === 'home')

<section class="mt-2 bg-card px-5 pt-4 pb-5 sm:px-6" aria-labelledby="customer-{{ $prefix }}-heading">
    <h3 id="customer-{{ $prefix }}-heading" class="mt-1 mb-5 flex items-center gap-2.5 border-t-2 border-table-line pt-4 text-heading font-semibold text-ink-strong">
        <x-icon name="house" />
        {{ $title }}
    </h3>

    <div class="min-w-0">
        <div class="flex items-baseline justify-between gap-3">
            <label for="customer-{{ $prefix }}Address" class="mb-1 block text-caption font-medium text-ink">Address<x-required-mark :when="$required" /></label>
            @if ($prefix === 'billing' && ! $readOnly)
                <button type="button" class="mb-1 text-caption text-ink italic underline underline-offset-2 hover:text-ink-strong" wire:click="copyHomeAddress">
                    Same as Home Address
                </button>
            @endif
        </div>
        <input
            id="customer-{{ $prefix }}Address"
            type="text"
            wire:model="values.{{ $prefix }}Address"
            placeholder="Add address"
            autocomplete="off"
            @if ($required) aria-required="true" @endif
            class="form-control !h-[34px] !text-body"
            @error("values.{$prefix}Address") aria-invalid="true" aria-describedby="customer-{{ $prefix }}Address-error" @enderror
        >
        @error("values.{$prefix}Address")
            <p id="customer-{{ $prefix }}Address-error" class="form-error" role="alert">{{ $message }}</p>
        @enderror
    </div>

    <div class="mt-4 grid grid-cols-1 gap-x-5 gap-y-3.5 min-[560px]:grid-cols-2">
        @include('livewire.customers.partials.select', ['key' => "{$prefix}CityId", 'label' => 'City', 'placeholder' => 'Select city', 'choices' => $locations['cities'], 'live' => true, 'required' => $required])
        @include('livewire.customers.partials.select', ['key' => "{$prefix}ProvinceId", 'label' => 'State/Province', 'placeholder' => 'Select province', 'choices' => $locations['provinces'], 'live' => true, 'required' => $required])
        @include('livewire.customers.partials.input', ['key' => "{$prefix}ZipCode", 'label' => 'Zip/Postal code', 'placeholder' => 'Input code', 'required' => $required])
        @include('livewire.customers.partials.select', ['key' => "{$prefix}CountryId", 'label' => 'Country/Region', 'placeholder' => 'Select country/region', 'choices' => $locations['countries'], 'live' => true, 'required' => $required])
    </div>

    @if ($prefix === 'home')
        @include('livewire.customers.partials.map')
    @endif
</section>
