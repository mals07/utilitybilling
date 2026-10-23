@use('App\Livewire\Rates\RatesPage')
@use('App\Support\Format')

@php
    $disabled = $selected === null;
    $canSubmitType = $rateType !== '' ? $access['edit'] : $access['add'];
    $rangeErrors = collect(['rangeTo', 'ratePerCount', 'rangeDescription', 'ranges'])
        ->map(fn (string $field) => $errors->first($field))
        ->filter()
        ->unique();
    $lastRangeId = $selected?->ranges->last()?->id;
@endphp

<x-page title="Rates" guide="rates">
    <x-flash class="mb-4" />

    <div class="grid grid-cols-1 gap-2 lg:grid-cols-[minmax(0,45fr)_minmax(0,55fr)]">
        {{-- Rate types --}}
        <section aria-label="Rate types" class="min-w-0 bg-panel p-5 sm:px-6">
            <form wire:submit="saveRateType" novalidate>
                <div>
                    <label for="rate-type-name" class="form-label">Rate Type<x-required-mark /></label>
                    <input
                        id="rate-type-name"
                        type="text"
                        wire:model="rateTypeName"
                        aria-required="true"
                        class="form-control"
                        @error('rateTypeName') aria-invalid="true" aria-describedby="rate-type-name-error" @enderror
                    >
                    @error('rateTypeName')
                        <p id="rate-type-name-error" class="form-error" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="mt-3">
                    <label for="rate-type-description" class="form-label">Description</label>
                    <textarea
                        id="rate-type-description"
                        rows="4"
                        wire:model="rateTypeDescription"
                        placeholder="Input text"
                        class="form-control"
                        @error('rateTypeDescription') aria-invalid="true" aria-describedby="rate-type-description-error" @enderror
                    ></textarea>
                    @error('rateTypeDescription')
                        <p id="rate-type-description-error" class="form-error" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="form-actions">
                    @if ($rateType !== '')
                        <button type="button" class="text-button" wire:click="cancelSelection">Cancel</button>
                    @endif
                    @if ($canSubmitType)
                        <button type="submit" class="form-button" wire:loading.attr="disabled" wire:target="saveRateType">
                            {{ $rateType !== '' ? 'Update Rate Type' : 'Add Rate Type' }}
                        </button>
                    @endif
                </div>
            </form>

            <hr class="form-divider">

            <div class="mt-12">
                <x-search-box label="Search rate types" wire:model.live.debounce.300ms="search" />
            </div>

            <div class="table-scroller mt-2">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col" class="cell-number">No</th>
                            <th scope="col">Rate Type</th>
                            <th scope="col" class="cell-actions"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rateTypes as $index => $type)
                            @php($isSelected = $type->id === $selected?->id)
                            <tr
                                wire:key="rate-type-{{ $type->id }}"
                                wire:click="select({{ $type->id }})"
                                @class(['row-clickable', 'row-selected' => $isSelected])
                            >
                                <td class="cell-number">{{ $rateTypes->firstItem() + $index }}</td>
                                <td>{{ $type->name }}</td>
                                <td class="cell-actions">
                                    <button
                                        type="button"
                                        class="icon-button"
                                        wire:click.stop="select({{ $type->id }})"
                                        aria-label="Select {{ $type->name }}"
                                        aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
                                    >
                                        <x-icon name="gear" :size="16" />
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                        <x-filler-rows
                            :count="$rateTypes->count()"
                            :minimum="RatesPage::MINIMUM_ROWS"
                            :columns="3"
                            :empty-text="trim($search) === '' ? 'No rate types yet.' : 'No rate types match your search.'"
                        />
                    </tbody>
                </table>
            </div>

            <x-pagination :paginator="$rateTypes" />
        </section>

        {{-- Rate setup --}}
        <section aria-labelledby="rate-setup-heading" class="min-w-0 rounded-xl border border-table-line bg-card p-5 shadow-card sm:px-6">
            <h2 id="rate-setup-heading" class="text-lg font-bold text-ink-strong">Rate Setup</h2>

            <form wire:submit="saveSetup" novalidate class="mt-5 grid grid-cols-1 gap-x-12 gap-y-4 sm:grid-cols-[minmax(0,1fr)_168px]">
                <div class="min-w-0">
                    <label for="setup-rate-type" class="form-label">Rate Type</label>
                    <input
                        id="setup-rate-type"
                        type="text"
                        value="{{ $selected?->name }}"
                        placeholder="{{ $selected ? '' : 'Select a rate type from the table' }}"
                        readonly
                        class="form-control"
                    >

                    <label for="setup-description" class="form-label mt-3">Description</label>
                    <textarea id="setup-description" rows="4" placeholder="Input text" readonly class="form-control">{{ $selected?->description }}</textarea>
                </div>

                <div class="flex flex-col">
                    <label for="minimum-consumption" class="form-label">Minimum Consumption</label>
                    <input
                        id="minimum-consumption"
                        type="number"
                        min="0"
                        step="1"
                        inputmode="numeric"
                        wire:model="minimumConsumption"
                        @disabled($disabled)
                        class="form-control"
                        @error('minimumConsumption') aria-invalid="true" aria-describedby="minimum-consumption-error" @enderror
                    >
                    @error('minimumConsumption')
                        <p id="minimum-consumption-error" class="form-error" role="alert">{{ $message }}</p>
                    @enderror

                    <fieldset class="mt-4 min-w-0 border-0 p-0">
                        <legend class="form-legend">Defective Computation</legend>
                        <label class="check mt-2">
                            <input type="checkbox" wire:model="defectiveUsesMinimumConsumption" @disabled($disabled)>
                            Minimum Consumption
                        </label>
                        <label class="check mt-1.5">
                            <input type="checkbox" wire:model="defectiveUsesThreeMonthAverage" @disabled($disabled)>
                            3 months average
                        </label>
                    </fieldset>

                    @if ($access['edit'])
                        <div class="mt-auto flex justify-end pt-4">
                            <button type="submit" class="form-button form-button-sm w-full sm:w-[100px]" @disabled($disabled) wire:loading.attr="disabled">
                                Save
                            </button>
                        </div>
                    @endif
                </div>
            </form>

            <hr class="form-divider">

            <form wire:submit="addRange" novalidate class="mt-3">
                <div class="flex flex-wrap items-end gap-x-4 gap-y-3">
                    <div>
                        <span id="consumption-range-label" class="form-label">Consumption Range<x-required-mark /></span>
                        <div class="flex items-center gap-3" role="group" aria-labelledby="consumption-range-label">
                            <output aria-label="Range from" class="flex h-8 min-w-12 items-center justify-center bg-panel px-2 text-sm text-accent">{{ $nextRangeFrom }}</output>
                            <label for="range-to" class="text-sm font-bold text-ink-strong">To</label>
                            <input
                                id="range-to"
                                type="number"
                                min="{{ $nextRangeFrom }}"
                                step="1"
                                inputmode="numeric"
                                wire:model="rangeTo"
                                placeholder="0"
                                aria-required="true"
                                @disabled($disabled)
                                class="form-control form-control-sm w-[72px] text-right placeholder:text-ink"
                                @error('rangeTo') aria-invalid="true" @enderror
                            >
                        </div>
                    </div>

                    <div>
                        <label for="rate-per-count" class="form-label">Rate per count<x-required-mark /></label>
                        <input
                            id="rate-per-count"
                            type="number"
                            min="0"
                            step="0.01"
                            inputmode="decimal"
                            wire:model="ratePerCount"
                            placeholder="0.00"
                            aria-required="true"
                            @disabled($disabled)
                            class="form-control form-control-sm w-24 text-right placeholder:text-ink"
                            @error('ratePerCount') aria-invalid="true" @enderror
                        >
                    </div>

                    <div class="min-w-28 flex-1">
                        <label for="range-description" class="form-label">Range Description<x-required-mark /></label>
                        <input
                            id="range-description"
                            type="text"
                            wire:model="rangeDescription"
                            aria-required="true"
                            @disabled($disabled)
                            class="form-control form-control-sm"
                            @error('rangeDescription') aria-invalid="true" @enderror
                        >
                    </div>

                    @if ($access['add'])
                        <button type="submit" class="form-button form-button-sm ml-auto w-full sm:w-[100px]" @disabled($disabled) wire:loading.attr="disabled">
                            Add Range
                        </button>
                    @endif
                </div>

                @if ($rangeErrors->isNotEmpty())
                    <ul class="mt-1 list-none p-0" role="alert">
                        @foreach ($rangeErrors as $message)
                            <li class="form-error">{{ $message }}</li>
                        @endforeach
                    </ul>
                @endif
            </form>

            <div class="table-scroller mt-4">
                <table class="data-table table-compact [&_thead_tr]:h-10">
                    <thead>
                        <tr>
                            <th scope="col" class="cell-center w-11">No</th>
                            <th scope="col" class="cell-center w-20">Range From</th>
                            <th scope="col" class="cell-center w-[72px]">Range To</th>
                            <th scope="col" class="cell-center w-[52px]">Rate</th>
                            <th scope="col" class="cell-center">Description</th>
                            <th scope="col" class="cell-actions w-11"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($selected?->ranges ?? [] as $index => $range)
                            <tr wire:key="range-{{ $range->id }}">
                                <td class="cell-number">{{ $index + 1 }}</td>
                                <td class="cell-center">{{ Format::integer($range->range_from) }}</td>
                                <td class="cell-center">{{ Format::integer($range->range_to) }}</td>
                                <td class="cell-right">{{ Format::amount($range->rate) }}</td>
                                <td class="cell-right">{{ $range->description }}</td>
                                <td class="cell-actions">
                                    @if ($range->id === $lastRangeId && $access['delete'])
                                        <button
                                            type="button"
                                            class="icon-button icon-button-danger"
                                            wire:click="deleteRange({{ $range->id }})"
                                            wire:confirm="Delete the range {{ $range->range_from }} to {{ $range->range_to }}?"
                                            wire:loading.attr="disabled"
                                            aria-label="Delete range {{ $range->range_from }} to {{ $range->range_to }}"
                                        >
                                            <x-icon name="trash" :size="16" />
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        <x-filler-rows :count="$selected?->ranges->count() ?? 0" :minimum="RatesPage::MINIMUM_ROWS" :columns="6" />
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-page>
