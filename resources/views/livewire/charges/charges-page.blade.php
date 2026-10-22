@use('App\Livewire\Charges\ChargesPage')
@use('App\Support\Format')

@php($canSubmitType = $type !== '' ? $access['edit'] : $access['add'])

<x-page title="Charges" guide="charges">
    <x-flash class="mb-4" />

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] lg:gap-8">
        {{-- Charge types --}}
        <section aria-label="Charge types" class="min-w-0 bg-panel p-5 sm:px-6">
            <form wire:submit="saveChargeType" novalidate>
                <div>
                    <label for="charge-type-name" class="form-label">Charge Type<x-required-mark /></label>
                    <input
                        id="charge-type-name"
                        type="text"
                        wire:model="chargeTypeName"
                        aria-required="true"
                        class="form-control"
                        @error('chargeTypeName') aria-invalid="true" aria-describedby="charge-type-name-error" @enderror
                    >
                    @error('chargeTypeName')
                        <p id="charge-type-name-error" class="form-error" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="mt-3">
                    <label for="charge-type-description" class="form-label">Description</label>
                    <textarea
                        id="charge-type-description"
                        rows="3"
                        wire:model="chargeTypeDescription"
                        placeholder="Input text"
                        class="form-control"
                        @error('chargeTypeDescription') aria-invalid="true" aria-describedby="charge-type-description-error" @enderror
                    ></textarea>
                    @error('chargeTypeDescription')
                        <p id="charge-type-description-error" class="form-error" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="form-actions">
                    @if ($type !== '')
                        <button type="button" class="text-button" wire:click="cancelType">Cancel</button>
                    @endif
                    @if ($canSubmitType)
                        <button type="submit" class="form-button" wire:loading.attr="disabled" wire:target="saveChargeType">
                            {{ $type !== '' ? 'Update Charge Type' : 'Add Charge Type' }}
                        </button>
                    @endif
                </div>
            </form>

            <hr class="form-divider">

            <div class="mt-12">
                <x-search-box label="Search charge types" wire:model.live.debounce.300ms="typeSearch" />
            </div>

            <div class="table-scroller mt-2">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col" class="cell-number">No</th>
                            <th scope="col">Charge Type</th>
                            <th scope="col" class="w-24">Date Added</th>
                            <th scope="col" class="cell-actions"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($chargeTypes as $index => $chargeType)
                            <tr
                                wire:key="charge-type-{{ $chargeType->id }}"
                                wire:click="selectType({{ $chargeType->id }})"
                                @class(['row-clickable', 'row-selected' => $chargeType->id === $selectedType?->id])
                            >
                                <td class="cell-number">{{ $chargeTypes->firstItem() + $index }}</td>
                                <td>{{ $chargeType->name }}</td>
                                <td class="cell-muted text-xs whitespace-nowrap">{{ Format::date($chargeType->created_at, $timezone) }}</td>
                                <td class="cell-actions">
                                    <button
                                        type="button"
                                        class="icon-button"
                                        wire:click.stop="openSetup({{ $chargeType->id }})"
                                        aria-label="Set up charge elements for {{ $chargeType->name }}"
                                    >
                                        <x-icon name="gear" :size="16" />
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                        <x-filler-rows
                            :count="$chargeTypes->count()"
                            :minimum="ChargesPage::MINIMUM_ROWS"
                            :columns="4"
                            :empty-text="trim($typeSearch) === '' ? 'No charge types yet.' : 'No charge types match your search.'"
                        />
                    </tbody>
                </table>
            </div>

            <x-pagination :paginator="$chargeTypes" />
        </section>

        <div class="hidden w-px bg-line lg:block" aria-hidden="true"></div>

        {{-- Charge elements --}}
        <section aria-labelledby="charge-elements-heading" class="min-w-0 py-1">
            <h2 id="charge-elements-heading" class="text-right text-base font-normal text-ink italic">Charge Elements</h2>

            <form wire:submit="addElement" novalidate class="mt-6">
                @include('livewire.charges.partials.element-fields', [
                    'prefix' => 'new-element',
                    'nameField' => 'elementName',
                    'distributionField' => 'elementDistribution',
                    'afterDueField' => 'elementAfterDue',
                    'taxableField' => 'elementTaxable',
                ])
                @if ($access['add'])
                    <div class="form-actions">
                        <button type="submit" class="form-button form-button-sm" wire:loading.attr="disabled" wire:target="addElement">
                            Add Charge Element
                        </button>
                    </div>
                @endif
            </form>

            <div class="mt-5">
                <x-search-box label="Search charge elements" :width="176" wire:model.live.debounce.300ms="elementSearch" />
            </div>

            <div class="table-scroller mt-2">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col" class="cell-number">No</th>
                            <th scope="col" class="cell-center">Charge Elements</th>
                            <th scope="col" class="cell-center w-24">Type</th>
                            <th scope="col" class="cell-center w-20">After Due</th>
                            <th scope="col" class="cell-center w-20">Taxable</th>
                            <th scope="col" class="cell-actions"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($chargeElements as $index => $element)
                            <tr wire:key="element-{{ $element->id }}">
                                <td class="cell-number">{{ $chargeElements->firstItem() + $index }}</td>
                                <td>{{ $element->name }}</td>
                                <td class="cell-center cell-muted">{{ $element->distribution_type->label() }}</td>
                                <td class="cell-center cell-muted">{{ $element->is_after_due ? 'Yes' : 'No' }}</td>
                                <td class="cell-center cell-muted">{{ $element->is_taxable ? 'Yes' : 'No' }}</td>
                                <td class="cell-actions">
                                    @if ($access['edit'])
                                        <button type="button" class="icon-button" wire:click="editElement({{ $element->id }})" aria-label="Edit {{ $element->name }}">
                                            <x-icon name="pencil-square" :size="16" />
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        <x-filler-rows
                            :count="$chargeElements->count()"
                            :minimum="ChargesPage::MINIMUM_ROWS"
                            :columns="6"
                            :empty-text="trim($elementSearch) === '' ? 'No charge elements yet.' : 'No charge elements match your search.'"
                        />
                    </tbody>
                </table>
            </div>

            <x-pagination :paginator="$chargeElements" />
        </section>
    </div>

    {{-- Update Charge Element --}}
    @if ($editingElementId !== null)
        <x-modal title="Update Charge Element" close="$wire.closeElementModal()">
            <form wire:submit="updateElement" novalidate class="mt-8">
                @include('livewire.charges.partials.element-fields', [
                    'prefix' => 'edit-element',
                    'nameField' => 'editName',
                    'distributionField' => 'editDistribution',
                    'afterDueField' => 'editAfterDue',
                    'taxableField' => 'editTaxable',
                    'autofocus' => true,
                ])
                <div class="form-actions">
                    <button type="submit" class="form-button form-button-sm" wire:loading.attr="disabled" wire:target="updateElement">
                        Update Charge Element
                    </button>
                </div>
            </form>
        </x-modal>
    @endif

    {{-- Charge Type Setup --}}
    @if ($setupType !== null)
        @include('livewire.charges.partials.setup-modal')
    @endif
</x-page>
