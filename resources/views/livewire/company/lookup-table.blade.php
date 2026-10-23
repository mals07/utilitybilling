@use('App\Livewire\Company\LookupTable')

@php
    $access = $this->access;
    $errorId = "{$config->value}-name-error";
@endphp

<section aria-label="{{ $config->plural() }}" class="flex min-w-0 flex-col">
    @if ($access['add'])
        <form wire:submit="add" novalidate>
            <div class="flex h-12 items-stretch">
                <label class="float min-w-0 flex-1 rounded-r-none border-r-0">
                    <span class="float-label">{{ $config->heading() }}<x-required-mark /></span>
                    <input
                        type="text"
                        wire:model="name"
                        placeholder=" "
                        aria-required="true"
                        class="float-control"
                        @error('name') aria-invalid="true" aria-describedby="{{ $errorId }}" @enderror
                    >
                </label>
                <button
                    type="submit"
                    class="inline-flex w-[88px] shrink-0 items-center justify-center gap-1 rounded-r-xs bg-muted text-xs font-medium text-white hover:enabled:bg-button focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-line-focus disabled:cursor-progress disabled:opacity-70"
                    wire:loading.attr="disabled"
                >
                    <span aria-hidden="true">+</span> Add
                </button>
            </div>
            @error('name')
                <p id="{{ $errorId }}" class="form-error" role="alert">{{ $message }}</p>
            @enderror
        </form>
    @endif

    <div class="mt-3 flex justify-end">
        <x-search-box :label="'Search '.strtolower($config->plural())" :width="112" size="sm" wire:model.live.debounce.300ms="search" />
    </div>

    <div class="relative mt-2 max-h-[248px] overflow-auto rounded-xl border border-table-line bg-card shadow-card">
        <table class="data-table border-0 [&_td]:border-x-0 [&_td]:border-t-0 [&_td+td]:border-l [&_th]:sticky [&_th]:top-0 [&_th]:z-[1] [&_th]:h-8 [&_th]:border-x-0 [&_th]:border-t-0 [&_th]:bg-card [&_th+th]:border-l [&_tbody_tr]:h-[26px]">
            <thead>
                <tr>
                    <th scope="col" class="cell-center">{{ $config->heading() }}</th>
                    @if ($config->tracksDialDigits())
                        <th scope="col" class="cell-center w-14 !text-[11px] !font-medium" title="Number of dials, e.g. 4 for a meter that reads up to 9999">Dials</th>
                    @endif
                    @if ($config->tracksTaxExempt())
                        <th scope="col" class="cell-center w-14 !text-[11px] !font-medium" title="Billed without tax, e.g. Government">Tax exempt</th>
                    @endif
                    @if ($config->tracksActive())
                        <th scope="col" class="cell-center w-14 !text-[11px] !font-medium">Active</th>
                    @endif
                    <th scope="col" class="cell-actions"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($items as $item)
                    <tr wire:key="item-{{ $item->id }}" @class(['row-inactive' => $config->tracksActive() && ! $item->is_active])>
                        <td>{{ $item->name }}</td>
                        @if ($config->tracksDialDigits())
                            <td class="cell-center !p-0.5">
                                <input
                                    type="text"
                                    inputmode="numeric"
                                    maxlength="2"
                                    value="{{ $item->dial_digits }}"
                                    placeholder="—"
                                    @disabled(! $access['edit'])
                                    x-on:change="$wire.setDialDigits({{ $item->id }}, $el.value)"
                                    aria-label="Number of dials of {{ $item->name }}"
                                    class="h-[22px] w-10 rounded-xs border border-transparent bg-transparent text-center text-xs hover:border-line focus:border-line-focus focus:outline-none disabled:cursor-not-allowed"
                                >
                            </td>
                        @endif
                        @if ($config->tracksTaxExempt())
                            <td class="cell-center">
                                <input
                                    type="checkbox"
                                    @checked($item->is_tax_exempt)
                                    @disabled(! $access['edit'])
                                    wire:click="toggleTaxExempt({{ $item->id }})"
                                    wire:loading.attr="disabled"
                                    aria-label="{{ $item->name }} is tax exempt"
                                    class="size-3.5 cursor-pointer align-middle accent-accent disabled:cursor-not-allowed"
                                >
                            </td>
                        @endif
                        @if ($config->tracksActive())
                            <td class="cell-center">
                                <input
                                    type="checkbox"
                                    @checked($item->is_active)
                                    @disabled(! $access['edit'])
                                    wire:click="toggleActive({{ $item->id }})"
                                    wire:loading.attr="disabled"
                                    aria-label="{{ $item->name }} is active"
                                    class="size-3.5 cursor-pointer align-middle accent-accent disabled:cursor-not-allowed"
                                >
                            </td>
                        @endif
                        <td class="cell-actions">
                            <button
                                type="button"
                                class="icon-button icon-button-danger"
                                @disabled(! $access['delete'])
                                wire:click="remove({{ $item->id }})"
                                wire:confirm="Remove {{ $item->name }}?"
                                aria-label="Remove {{ $item->name }}"
                            >
                                <x-icon name="trash" :size="14" />
                            </button>
                        </td>
                    </tr>
                @endforeach
                <x-filler-rows
                    :count="$items->count()"
                    :minimum="LookupTable::MINIMUM_ROWS"
                    :columns="$config->columnCount()"
                    :text-column="0"
                    :empty-text="$searching ? 'No matches.' : 'No '.strtolower($config->plural()).' yet.'"
                />
            </tbody>
        </table>
    </div>

    @if ($rowError)
        <p class="form-error" role="alert">{{ $rowError }}</p>
    @endif

    @if ($config->showsRemoved())
        <div class="mt-2 text-right">
            <button type="button" class="link-button" wire:click="$set('showingRemoved', true)">Removed {{ $config->label() }}</button>
        </div>

        @if ($showingRemoved)
            <x-modal :title="'Removed '.$config->plural()" close="$wire.closeRemoved()" width="sm">
                @if ($restoreError)
                    <p class="form-error" role="alert">{{ $restoreError }}</p>
                @endif
                <div class="relative mt-3 max-h-[60vh] overflow-auto">
                    <table class="data-table table-compact">
                        <thead>
                            <tr>
                                <th scope="col">{{ $config->label() }}</th>
                                <th scope="col" class="cell-center w-24">Date</th>
                                <th scope="col" class="cell-actions"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($removed as $item)
                                <tr wire:key="removed-{{ $item['id'] }}">
                                    <td>{{ $item['name'] }}</td>
                                    <td class="cell-center cell-muted text-xs"><time datetime="{{ $item['removedAt'] }}">{{ $item['removedOn'] }}</time></td>
                                    <td class="cell-actions">
                                        <button
                                            type="button"
                                            class="icon-button"
                                            @disabled(! $access['edit'])
                                            wire:click="restore({{ $item['id'] }})"
                                            aria-label="Restore {{ $item['name'] }}"
                                            title="Restore"
                                        >
                                            <x-icon name="restore" :size="14" />
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                            <x-filler-rows
                                :count="$removed->count()"
                                :minimum="LookupTable::MINIMUM_ROWS"
                                :columns="3"
                                :text-column="0"
                                :empty-text="'No removed '.strtolower($config->plural()).'.'"
                            />
                        </tbody>
                    </table>
                </div>
            </x-modal>
        @endif
    @endif
</section>
