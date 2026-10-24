@use('App\Livewire\Company\LookupTable')

@php
    $access = $this->access;
    $errorId = "{$config->value}-name-error";
@endphp

<section aria-label="{{ $config->plural() }}" class="flex h-full min-w-0 flex-col rounded-2xl border border-table-line bg-card p-3.5 shadow-card sm:p-4">
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
                    class="inline-flex w-[88px] shrink-0 items-center justify-center gap-1 rounded-r-xs bg-button text-body font-semibold text-white shadow-button transition-colors hover:enabled:bg-button-hover focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-line-focus disabled:cursor-progress disabled:opacity-70"
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

    <div class="mt-4 [&_.search-box]:w-full!">
        <x-search-box :label="'Search '.strtolower($config->plural())" :width="112" size="sm" wire:model.live.debounce.300ms="search" />
    </div>

    {{-- Fixed column widths so the table always fits its card and never scrolls sideways. --}}
    <div class="relative mt-3 h-[296px] overflow-x-hidden overflow-y-auto rounded-xl border border-table-line bg-card">
        <table class="data-table table-fixed border-0 text-body [&_td]:px-3 [&_td]:py-1 [&_td]:leading-tight [&_th]:sticky [&_th]:top-0 [&_th]:z-[1] [&_th]:h-10 [&_th]:px-3 [&_th]:py-0 [&_th]:text-caption [&_th]:leading-tight [&_th]:whitespace-normal [&_tbody_tr]:h-[42px]">
            <colgroup>
                <col>
                @if ($config->tracksDialDigits())
                    <col class="w-12">
                @endif
                @if ($config->tracksTaxExempt())
                    <col class="w-[60px]">
                @endif
                @if ($config->tracksActive())
                    <col class="w-[52px]">
                @endif
                <col class="w-10">
            </colgroup>
            <thead>
                <tr>
                    <th scope="col">{{ $config->heading() }}</th>
                    @if ($config->tracksDialDigits())
                        <th scope="col" class="cell-center !px-1" title="Number of dials, e.g. 4 for a meter that reads up to 9999">Dials</th>
                    @endif
                    @if ($config->tracksTaxExempt())
                        <th scope="col" class="cell-center !px-1" title="Billed without tax, e.g. Government">Tax exempt</th>
                    @endif
                    @if ($config->tracksActive())
                        <th scope="col" class="cell-center !px-1">Active</th>
                    @endif
                    <th scope="col" class="cell-actions !px-1"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($items as $item)
                    <tr wire:key="item-{{ $item->id }}" @class(['row-inactive' => $config->tracksActive() && ! $item->is_active])>
                        <td class="break-words">{{ $item->name }}</td>
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
                                    class="h-7 w-9 rounded-xs border border-transparent bg-transparent text-center text-body hover:border-line focus:border-line-focus focus:outline-none disabled:cursor-not-allowed"
                                >
                            </td>
                        @endif
                        @if ($config->tracksTaxExempt())
                            <td class="cell-center !px-1">
                                <input
                                    type="checkbox"
                                    @checked($item->is_tax_exempt)
                                    @disabled(! $access['edit'])
                                    wire:click="toggleTaxExempt({{ $item->id }})"
                                    wire:loading.attr="disabled"
                                    aria-label="{{ $item->name }} is tax exempt"
                                    class="size-4 cursor-pointer align-middle accent-accent disabled:cursor-not-allowed"
                                >
                            </td>
                        @endif
                        @if ($config->tracksActive())
                            <td class="cell-center !px-1">
                                <input
                                    type="checkbox"
                                    @checked($item->is_active)
                                    @disabled(! $access['edit'])
                                    wire:click="toggleActive({{ $item->id }})"
                                    wire:loading.attr="disabled"
                                    aria-label="{{ $item->name }} is active"
                                    class="size-4 cursor-pointer align-middle accent-accent disabled:cursor-not-allowed"
                                >
                            </td>
                        @endif
                        {{-- Row menu. It is fixed-positioned so the table's scroll box doesn't clip it. --}}
                        <td
                            class="cell-actions !px-1"
                            x-data="{
                                open: false,
                                top: 0,
                                left: 0,
                                toggle() {
                                    const box = this.$refs.trigger.getBoundingClientRect();
                                    this.top = box.bottom + 4;
                                    this.left = box.right;
                                    this.open = ! this.open;
                                },
                            }"
                            x-on:click.outside="open = false"
                            x-on:keydown.escape.window="if (open) { $event.preventDefault(); open = false; $refs.trigger.focus(); }"
                            x-on:scroll.window.capture="open = false"
                            x-on:resize.window="open = false"
                        >
                            <button
                                type="button"
                                x-ref="trigger"
                                class="icon-button"
                                @disabled(! $access['delete'])
                                x-on:click="toggle()"
                                aria-haspopup="menu"
                                x-bind:aria-expanded="open.toString()"
                                aria-label="Options for {{ $item->name }}"
                            >
                                <x-icon name="ellipsis-vertical" :size="16" />
                            </button>
                            <div
                                x-show="open"
                                x-cloak
                                x-transition.opacity.duration.100ms
                                role="menu"
                                class="fixed z-50 min-w-36 -translate-x-full rounded-xl border border-table-line bg-card p-1 text-left shadow-dialog"
                                x-bind:style="`top: ${top}px; left: ${left}px`"
                            >
                                <button
                                    type="button"
                                    role="menuitem"
                                    class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-body font-medium text-danger hover:bg-danger-bg focus-visible:bg-danger-bg focus-visible:outline-none"
                                    x-on:click="open = false"
                                    wire:click="remove({{ $item->id }})"
                                    wire:confirm="Remove {{ $item->name }}?"
                                >
                                    <x-icon name="trash" :size="14" />
                                    Delete<span class="sr-only"> {{ $item->name }}</span>
                                </button>
                            </div>
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
        <div class="mt-auto pt-3 text-right">
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
                                    <td class="cell-center cell-muted text-caption"><time datetime="{{ $item['removedAt'] }}">{{ $item['removedOn'] }}</time></td>
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
