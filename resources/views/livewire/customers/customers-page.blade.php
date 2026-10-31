@use('App\Livewire\Customers\CustomersPage')

<x-page title="Customers" guide="customers">
    <div class="max-w-[1100px]">
        <x-flash class="mb-4" />

        <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
            <x-search-box label="Search customers" :width="200" wire:model.live.debounce.300ms="search" />
            @if ($access['add'])
                <div class="flex flex-col items-end gap-1">
                    <button type="button" class="form-button form-button-sm min-w-40" wire:click="openAdd">Add Customer</button>
                    <livewire:customers.import-customers />
                </div>
            @endif
        </div>

        <div class="table-scroller mt-3">
            {{-- On narrow screens the Account Type (2nd) and Meter (3rd) columns are hidden, filler rows included. --}}
            <table class="data-table max-md:[&_tr>*:nth-child(2)]:hidden max-md:[&_tr>*:nth-child(3)]:hidden">
                <thead>
                    <tr>
                        <th scope="col">Customer</th>
                        <th scope="col">Account Type</th>
                        <th scope="col">Meter</th>
                        <th scope="col" class="cell-center w-px">Status</th>
                        <th scope="col" class="cell-center"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($customers as $item)
                        <tr
                            wire:key="customer-{{ $item->id }}"
                            @class(['row-selected' => $item->id === $selected?->id || $item->id === $historyCustomer?->id])
                        >
                            <td>
                                <span class="block font-medium text-ink-strong">{{ $item->first_name }} {{ $item->last_name }}</span>
                                <span class="mt-0.5 block text-caption whitespace-nowrap text-label tabular-nums">Acct No: {{ $item->account_number }}</span>
                            </td>
                            <td>
                                <span class="inline-block max-w-40 truncate rounded bg-panel px-2 py-0.5 align-middle text-caption text-ink-soft">{{ $item->accountType?->name }}</span>
                            </td>
                            <td>
                                @if ($item->meterType)
                                    <span class="block">{{ $item->meterType->name }}</span>
                                @else
                                    <span class="block text-placeholder">Not assigned</span>
                                @endif
                                @if ($item->meter_number)
                                    <span class="mt-0.5 block text-caption text-label">No. {{ $item->meter_number }}</span>
                                @endif
                            </td>
                            <td class="cell-center">
                                <span class="status-pill {{ $item->status->colorClass() }}">{{ $item->status->label() }}</span>
                            </td>
                            {{-- Row menu. It is fixed-positioned so the table's scroll box doesn't clip it. --}}
                            <td
                                class="cell-actions"
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
                                    x-on:click="toggle()"
                                    aria-haspopup="menu"
                                    x-bind:aria-expanded="open.toString()"
                                    aria-label="Options for {{ $item->first_name }} {{ $item->last_name }}"
                                >
                                    <x-icon name="menu" :size="16" />
                                </button>
                                <div
                                    x-show="open"
                                    x-cloak
                                    x-transition.opacity.duration.100ms
                                    role="menu"
                                    class="fixed z-50 min-w-40 -translate-x-full rounded-xl border border-table-line bg-card p-1 text-left shadow-dialog"
                                    x-bind:style="`top: ${top}px; left: ${left}px`"
                                >
                                    <button
                                        type="button"
                                        role="menuitem"
                                        class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-body font-medium text-ink-strong hover:bg-hover focus-visible:bg-hover focus-visible:outline-none"
                                        x-on:click="open = false"
                                        wire:click="openCustomer({{ $item->id }})"
                                    >
                                        <x-icon name="pencil" :size="14" />
                                        Edit<span class="sr-only"> {{ $item->first_name }} {{ $item->last_name }}</span>
                                    </button>
                                    <button
                                        type="button"
                                        role="menuitem"
                                        class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-body font-medium text-ink-strong hover:bg-hover focus-visible:bg-hover focus-visible:outline-none"
                                        x-on:click="open = false"
                                        wire:click="openHistory({{ $item->id }})"
                                    >
                                        <x-icon name="document" :size="14" />
                                        Show History<span class="sr-only"> of {{ $item->first_name }} {{ $item->last_name }}</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    <x-filler-rows
                        :count="$customers->count()"
                        :minimum="CustomersPage::MINIMUM_ROWS"
                        :columns="5"
                        :text-column="0"
                        :empty-text="trim($search) === '' ? 'No customers yet.' : 'No customers match your search.'"
                    />
                </tbody>
            </table>
        </div>

        <x-pagination :paginator="$customers" />
    </div>

    @if ($modalOpen)
        @include('livewire.customers.partials.customer-modal')
    @endif

    @if ($historyCustomer)
        @include('livewire.customers.partials.history-drawer')
    @endif
</x-page>
