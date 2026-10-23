@use('App\Livewire\Customers\CustomersPage')

<x-page title="Customers" guide="customers">
    <div class="max-w-[960px]">
        <x-flash class="mb-4" />

        <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
            <x-search-box label="Search customers" :width="200" wire:model.live.debounce.300ms="search" />
            @if ($access['add'])
                <div class="flex items-center gap-5">
                    <livewire:customers.import-customers />
                    <button type="button" class="form-button form-button-sm min-w-40" wire:click="openAdd">Add Customer</button>
                </div>
            @endif
        </div>

        <div class="table-scroller mt-3">
            <table class="data-table [&_th]:text-ink-strong [&_tbody_tr]:h-10">
                <thead>
                    <tr>
                        <th scope="col">Account Number</th>
                        <th scope="col">Firstname</th>
                        <th scope="col">Lastname</th>
                        <th scope="col">Account Type</th>
                        <th scope="col">Status</th>
                        <th scope="col">Meter Type</th>
                        <th scope="col" class="cell-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($customers as $item)
                        <tr wire:key="customer-{{ $item->id }}" @class(['row-selected' => $item->id === $selected?->id])>
                            <td class="whitespace-nowrap">{{ $item->account_number }}</td>
                            <td>{{ $item->first_name }}</td>
                            <td>{{ $item->last_name }}</td>
                            <td>
                                <span class="inline-block max-w-40 truncate rounded bg-panel px-2 py-0.5 align-middle text-xs text-ink-soft">{{ $item->accountType?->name }}</span>
                            </td>
                            <td>
                                <span class="text-[13px] whitespace-nowrap {{ $item->status->colorClass() }}">{{ $item->status->label() }}</span>
                            </td>
                            <td>
                                @if ($item->meterType)
                                    {{ $item->meterType->name }}
                                @else
                                    <span class="text-placeholder">Not assigned</span>
                                @endif
                                @if ($item->meter_number)
                                    <span class="block text-xs text-label">No. {{ $item->meter_number }}</span>
                                @endif
                            </td>
                            <td class="cell-actions">
                                <button type="button" class="icon-button" wire:click="openCustomer({{ $item->id }})" aria-label="Update {{ $item->first_name }} {{ $item->last_name }}">
                                    <x-icon name="pencil" :size="14" />
                                </button>
                            </td>
                        </tr>
                    @endforeach
                    <x-filler-rows
                        :count="$customers->count()"
                        :minimum="CustomersPage::MINIMUM_ROWS"
                        :columns="7"
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
</x-page>
