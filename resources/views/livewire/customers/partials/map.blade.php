{{-- "Map Location": the map is drawn by Leaflet, so Livewire leaves it alone (wire:ignore). --}}
<div class="mt-3 min-w-0">
    <span id="customer-location-label" class="mb-1 block text-caption font-medium text-ink">Map Location (Optional)</span>

    <div
        wire:ignore
        wire:key="map-{{ $selected?->id ?? 'new' }}"
        x-data="mapLocation({
            latitude: 'values.latitude',
            longitude: 'values.longitude',
            defaultArea: @js($mapArea ?: null),
            readOnly: @js($readOnly),
            // The home address as typed, for the map search; '' until an address or city is given.
            homeAddress: () => {
                const field = (id) => document.getElementById(id);
                const choice = (id) => {
                    const select = field(id);
                    return select && select.value !== '' ? select.selectedOptions[0].text.split(', ')[0] : '';
                };
                const address = field('customer-homeAddress')?.value.trim() ?? '';
                if (address === '' && field('customer-homeCityId')?.value === '') return '';
                return [address, choice('customer-homeCityId'), choice('customer-homeProvinceId'), field('customer-homeZipCode')?.value.trim(), choice('customer-homeCountryId')]
                    .filter(Boolean)
                    .join(', ');
            },
        })"
    >
        <template x-if="! readOnly">
            <div class="mb-2 flex flex-wrap gap-2">
                <input
                    type="search"
                    x-model="query"
                    placeholder="Search a place or address"
                    aria-label="Search the map"
                    autocomplete="off"
                    x-on:keydown.enter.prevent="search(query)"
                    class="form-control !h-[34px] min-w-0 flex-[1_1_200px] !text-body"
                >
                <button type="button" class="form-button form-button-sm" x-on:click="search(query)" x-bind:disabled="searching || query.trim() === ''">Search</button>
                <button
                    type="button"
                    class="form-button form-button-sm"
                    x-on:click="search(homeAddressText())"
                    x-bind:disabled="searching"
                    title="Uses the home address typed above."
                >
                    Use home address
                </button>
            </div>
        </template>

        <div
            x-ref="map"
            class="isolate h-[260px] rounded-xs border border-table-line bg-[#e8f0f7]"
            role="application"
            aria-labelledby="customer-location-label"
        ></div>

        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
            <span class="mt-1 text-caption text-placeholder" x-text="pinText"></span>
            <button type="button" class="link-button" x-show="pin && ! readOnly" x-on:click="place(null)">Remove pin</button>
        </div>
        <p class="mt-1 text-caption text-placeholder" role="status" x-show="message && ! readOnly" x-text="message"></p>
    </div>

    @error('location')
        <p id="customer-location-error" class="form-error" role="alert">{{ $message }}</p>
    @enderror
</div>
