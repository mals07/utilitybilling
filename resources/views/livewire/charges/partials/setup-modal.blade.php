@use('App\Livewire\Charges\ChargesPage')

@php
    $setupErrors = collect([$setupMessage, $errors->first('setupElementId'), $errors->first('setupValueType'), $errors->first('setupValue'), $errors->first('rowValueType'), $errors->first('rowValue')])
        ->filter()
        ->unique();
@endphp

<x-modal title="Charge Type Setup" close="$wire.closeSetup()" width="lg">
    <div class="mt-3">
        <label for="setup-charge-type" class="form-label">Charge Type</label>
        <input id="setup-charge-type" type="text" value="{{ $setupType->name }}" readonly class="form-control">

        <label for="setup-charge-type-description" class="form-label mt-3">Description</label>
        <textarea id="setup-charge-type-description" rows="2" placeholder="Input text" readonly class="form-control">{{ $setupType->description }}</textarea>
    </div>

    <div class="mt-4 border border-table-line bg-panel p-1">
        <form wire:submit="addSetupElement" novalidate class="flex flex-wrap items-stretch gap-1">
            {{-- Type-to-filter picker for the charge elements not yet added. Re-created after each add so it clears. --}}
            <div
                wire:key="combobox-{{ $setupRows->pluck('id')->implode('-') }}"
                class="relative min-w-0 flex-[2_1_192px]"
                x-data="{
                    options: @js($available),
                    query: '',
                    open: false,
                    active: 0,
                    selected: null,
                    init() {
                        this.$dispatch('setup-element-chosen', '');
                    },
                    get filtered() {
                        const search = this.query.trim().toLowerCase();
                        return search === '' ? this.options : this.options.filter((option) => option.name.toLowerCase().includes(search));
                    },
                    choose(option) {
                        if (! option) return;
                        this.selected = option;
                        this.query = option.name;
                        this.open = false;
                        this.$wire.setupElementId = option.id;
                        this.$dispatch('setup-element-chosen', option.type);
                    },
                    type(value) {
                        this.query = value;
                        this.open = true;
                        this.active = 0;
                        // Typing over a chosen element clears the choice until another is picked.
                        if (this.selected && this.selected.name !== value) {
                            this.selected = null;
                            this.$wire.setupElementId = null;
                            this.$dispatch('setup-element-chosen', '');
                        }
                    },
                    move(step) {
                        this.open = true;
                        const count = this.filtered.length;
                        if (count > 0) this.active = (this.active + step + count) % count;
                    },
                }"
                x-on:focusout="if (! $el.contains($event.relatedTarget)) open = false"
            >
                <div class="flex h-11 min-w-0 flex-col justify-center border border-table-line bg-card px-2 focus-within:border-line-focus">
                    <label for="setup-element" class="text-[10px] text-label">Charge Element</label>
                    <div class="flex items-center">
                        <input
                            id="setup-element"
                            type="text"
                            role="combobox"
                            autocomplete="off"
                            aria-autocomplete="list"
                            aria-controls="setup-element-options"
                            x-bind:aria-expanded="open.toString()"
                            x-bind:value="query"
                            x-on:input="type($event.target.value)"
                            x-on:focus="open = true"
                            x-on:keydown.arrow-down.prevent="move(1)"
                            x-on:keydown.arrow-up.prevent="move(-1)"
                            x-on:keydown.enter="if (open && filtered.length > 0) { $event.preventDefault(); choose(filtered[active]); }"
                            x-on:keydown.escape="if (open) { $event.preventDefault(); $event.stopPropagation(); open = false; }"
                            @error('setupElementId') aria-invalid="true" @enderror
                            class="h-5 w-full border-0 bg-transparent p-0 text-sm text-ink focus:outline-none"
                        >
                        <x-icon name="search" :size="14" class="text-accent" />
                    </div>
                </div>

                <ul
                    id="setup-element-options"
                    role="listbox"
                    wire:ignore
                    x-show="open"
                    x-cloak
                    class="absolute inset-x-0 top-full z-10 mt-1 max-h-48 list-none overflow-y-auto rounded-xs border border-table-line bg-card py-1 text-sm shadow-dialog"
                >
                    <template x-for="(option, index) in filtered" x-bind:key="option.id">
                        <li
                            role="option"
                            x-bind:aria-selected="(index === active).toString()"
                            x-bind:class="index === active && 'bg-panel'"
                            class="flex cursor-pointer items-center justify-between gap-3 px-3 py-1.5"
                            x-on:mousedown.prevent="choose(option)"
                            x-on:mouseenter="active = index"
                        >
                            <span class="truncate" x-text="option.name"></span>
                            <span class="text-xs text-muted" x-text="option.type"></span>
                        </li>
                    </template>
                    <li x-show="filtered.length === 0" class="px-3 py-1.5 text-xs text-placeholder" x-text="options.length === 0 ? 'No charge elements left to add.' : 'No charge elements match.'"></li>
                </ul>
            </div>

            <fieldset class="m-0 flex h-11 min-w-32 flex-col justify-center border border-table-line bg-card px-2">
                <legend class="sr-only">Value type</legend>
                <label class="check">
                    <input type="radio" name="setup-value-type" value="fixed" wire:model="setupValueType" class="check-square">
                    Fixed Amount
                </label>
                <label class="check mt-1">
                    <input type="radio" name="setup-value-type" value="percentage" wire:model="setupValueType" class="check-square">
                    Percentage
                </label>
            </fieldset>

            <div class="flex h-11 w-28 min-w-0 flex-col justify-center border border-table-line bg-card px-2 focus-within:border-line-focus">
                <label for="setup-value" class="text-[10px] text-label" wire:ignore x-data x-text="$wire.setupValueType === 'percentage' ? 'Value (%)' : 'Value'">Value</label>
                <input
                    id="setup-value"
                    type="number"
                    min="0"
                    step="0.01"
                    inputmode="decimal"
                    wire:model="setupValue"
                    placeholder="0.00"
                    @error('setupValue') aria-invalid="true" @enderror
                    class="h-5 w-full border-0 bg-transparent p-0 text-right text-sm text-ink focus:outline-none"
                >
            </div>

            <div class="flex h-11 w-28 min-w-0 flex-col justify-center border border-table-line bg-card px-2">
                <span id="setup-distribution-label" class="text-[10px] text-label">Distribution Type</span>
                <output
                    aria-labelledby="setup-distribution-label"
                    class="h-5 text-sm text-ink"
                    wire:ignore
                    x-data="{ type: '' }"
                    x-on:setup-element-chosen.window="type = $event.detail"
                    x-text="type"
                ></output>
            </div>

            @if ($access['add'])
                <button
                    type="submit"
                    class="h-11 w-full bg-button px-6 text-xs font-medium text-white hover:enabled:bg-button-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-line-focus disabled:cursor-progress disabled:opacity-70 sm:w-28"
                    wire:loading.attr="disabled"
                    wire:target="addSetupElement"
                >
                    ADD
                </button>
            @endif
        </form>

        @if ($setupErrors->isNotEmpty())
            <ul class="mt-1 list-none px-1 pb-1" role="alert">
                @foreach ($setupErrors as $message)
                    <li class="form-error">{{ $message }}</li>
                @endforeach
            </ul>
        @endif

        <div class="table-scroller mt-1">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col" class="cell-number">No</th>
                        <th scope="col">Charge Elements</th>
                        <th scope="col" class="cell-right w-44">Value</th>
                        <th scope="col" class="w-20">Type</th>
                        <th scope="col" class="w-20">After Due</th>
                        <th scope="col" class="cell-actions w-10"><span class="sr-only">Edit</span></th>
                        <th scope="col" class="cell-actions w-10"><span class="sr-only">Delete</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($setupRows as $index => $row)
                        @php($isEditing = $editingRowId === $row->id)
                        <tr wire:key="setup-row-{{ $row->id }}" @class(['row-selected' => $isEditing])>
                            <td class="cell-number">{{ $index + 1 }}</td>
                            <td>{{ $row->chargeElement->name }}</td>
                            <td class="cell-right">
                                @if ($isEditing)
                                    <div class="flex items-center justify-end gap-1">
                                        <label for="edit-row-type-{{ $row->id }}" class="sr-only">Value type</label>
                                        <select id="edit-row-type-{{ $row->id }}" wire:model="rowValueType" class="h-7 rounded-xs border border-line bg-card px-1 text-xs">
                                            <option value="fixed">Fixed</option>
                                            <option value="percentage">%</option>
                                        </select>
                                        <label for="edit-row-value-{{ $row->id }}" class="sr-only">Value</label>
                                        <input
                                            id="edit-row-value-{{ $row->id }}"
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            inputmode="decimal"
                                            wire:model="rowValue"
                                            wire:keydown.enter.prevent="saveRowEdit"
                                            x-on:keydown.escape.prevent.stop="$wire.cancelRowEdit()"
                                            x-init="$el.focus()"
                                            @error('rowValue') aria-invalid="true" @enderror
                                            class="form-control !h-7 !w-20 !px-1.5 text-right !text-xs"
                                        >
                                    </div>
                                @else
                                    {{ $row->value_type->format($row->value) }}
                                @endif
                            </td>
                            <td class="cell-muted">{{ $row->chargeElement->distribution_type->label() }}</td>
                            <td class="cell-muted">{{ $row->chargeElement->is_after_due ? 'Yes' : 'No' }}</td>
                            <td class="cell-actions">
                                @if ($isEditing)
                                    <button type="button" class="icon-button icon-button-confirm" wire:click="saveRowEdit" wire:loading.attr="disabled" aria-label="Save {{ $row->chargeElement->name }}">
                                        <x-icon name="check" :size="16" />
                                    </button>
                                @elseif ($access['edit'])
                                    <button type="button" class="icon-button" wire:click="startRowEdit({{ $row->id }})" aria-label="Edit {{ $row->chargeElement->name }}">
                                        <x-icon name="pencil" :size="16" />
                                    </button>
                                @endif
                            </td>
                            <td class="cell-actions">
                                @if ($isEditing)
                                    <button type="button" class="icon-button" wire:click="cancelRowEdit" aria-label="Cancel editing {{ $row->chargeElement->name }}">
                                        <x-icon name="close" :size="16" />
                                    </button>
                                @elseif ($access['delete'])
                                    <button
                                        type="button"
                                        class="icon-button icon-button-danger"
                                        wire:click="deleteSetupRow({{ $row->id }})"
                                        wire:confirm="Remove {{ $row->chargeElement->name }} from {{ $setupType->name }}?"
                                        wire:loading.attr="disabled"
                                        aria-label="Remove {{ $row->chargeElement->name }}"
                                    >
                                        <x-icon name="trash" :size="16" />
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    <x-filler-rows :count="$setupRows->count()" :minimum="ChargesPage::MINIMUM_ROWS" :columns="7" empty-text="No charge elements set up yet." />
                </tbody>
            </table>
        </div>
    </div>
</x-modal>
