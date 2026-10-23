@use('App\Enums\WorkOrderType')
@use('App\Livewire\WorkOrders\WorkOrdersPage')
@use('App\Models\WorkOrder')
@use('App\Support\Format')

@php($meterTypeHint = 'Add meter types in Company Setup → Operation Setup.')

<x-page title="Work Orders" guide="work-orders">
    <div class="max-w-[1280px]">
        <x-flash class="mb-4" />

        {{-- Submit Work Order --}}
        @if ($access['add'])
            <form
                wire:submit="submit"
                novalidate
                x-data
                x-on:focus-first-error.window="$nextTick(() => $el.querySelector('[aria-invalid=true]')?.focus())"
            >
                <fieldset class="m-0 grid min-w-0 grid-cols-1 gap-x-5 gap-y-3 border-0 p-0 sm:grid-cols-2 lg:grid-cols-4" wire:loading.attr="disabled" wire:target="submit">
                    <legend class="sr-only">New work order</legend>

                    {{-- Account No.: search customers by account number or name, then pick one. --}}
                    <div
                        class="relative min-w-0 lg:col-start-3 lg:row-start-1"
                        x-data="{ open: false }"
                        x-on:click.outside="open = false"
                        x-on:keydown.escape="if (open) { open = false; $event.stopPropagation(); }"
                    >
                        <label for="work-order-account" class="mb-1 block text-xs font-semibold text-ink-strong">Account No.<x-required-mark /></label>
                        <div class="relative">
                            <input
                                id="work-order-account"
                                type="search"
                                wire:model.live.debounce.300ms="customerSearch"
                                x-on:focus="open = true"
                                x-on:input="open = true"
                                placeholder="Account no. or name"
                                autocomplete="off"
                                role="combobox"
                                aria-autocomplete="list"
                                aria-controls="work-order-account-matches"
                                x-bind:aria-expanded="open.toString()"
                                aria-required="true"
                                class="form-control !h-[34px] !pr-8 !text-[13px]"
                                @error('customerId') aria-invalid="true" aria-describedby="work-order-account-error" @enderror
                            >
                            <x-icon name="search" :size="14" class="pointer-events-none absolute top-1/2 right-2.5 -translate-y-1/2 text-accent" />
                        </div>

                        @if (trim($customerSearch) !== '' && $chosenCustomer === null)
                            <ul
                                id="work-order-account-matches"
                                role="listbox"
                                x-show="open"
                                class="absolute inset-x-0 top-full z-20 mt-1 max-h-64 overflow-y-auto rounded-xs border border-line bg-card py-1 shadow-lg"
                            >
                                @forelse ($customerMatches as $match)
                                    <li wire:key="match-{{ $match->id }}" role="option" aria-selected="false">
                                        <button
                                            type="button"
                                            class="flex w-full items-baseline gap-2 px-3 py-1.5 text-left text-[13px] hover:bg-hover focus-visible:bg-hover focus-visible:outline-none"
                                            wire:click="chooseCustomer({{ $match->id }})"
                                            x-on:click="open = false"
                                        >
                                            <span class="font-medium text-ink-strong">{{ $match->account_number }}</span>
                                            <span class="truncate text-ink-soft">{{ $match->first_name }} {{ $match->last_name }}</span>
                                        </button>
                                    </li>
                                @empty
                                    <li class="px-3 py-1.5 text-[13px] text-placeholder">No customers match.</li>
                                @endforelse
                            </ul>
                        @endif

                        @error('customerId')
                            <p id="work-order-account-error" class="form-error" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Name: filled in from the chosen customer. --}}
                    <div class="min-w-0 sm:col-span-2 lg:col-start-1 lg:row-start-1">
                        <span class="mb-1 block text-xs font-semibold text-ink-strong" id="work-order-name-label">Name</span>
                        <div class="grid grid-cols-2 gap-3" role="group" aria-labelledby="work-order-name-label">
                            <input type="text" readonly tabindex="-1" aria-label="First name" placeholder="First name" value="{{ $chosenCustomer?->first_name }}" class="form-control !h-[34px] !text-[13px]">
                            <input type="text" readonly tabindex="-1" aria-label="Last name" placeholder="Last name" value="{{ $chosenCustomer?->last_name }}" class="form-control !h-[34px] !text-[13px]">
                        </div>
                    </div>

                    <div class="lg:col-start-4 lg:row-start-1">
                        @include('livewire.work-orders.partials.field', ['model' => 'values', 'key' => 'orderType', 'label' => 'Order Type', 'placeholder' => 'Select Order Type', 'choices' => $orderTypes, 'disabledChoices' => $ineligibleTypes, 'required' => true, 'live' => true])
                        @if ($chosenCustomer === null)
                            <p class="mt-1 text-xs text-placeholder">Pick a customer to see which types apply to them.</p>
                        @endif
                    </div>

                    @if ($chosenType?->isOfficeWork())
                        <p class="text-xs text-label sm:col-span-2 lg:col-span-2 lg:col-start-3 lg:row-start-2">
                            {{ $chosenType->label() }} is office work, so no meter details are needed.
                        </p>
                    @else
                        <div class="lg:col-start-3 lg:row-start-2">
                            @include('livewire.work-orders.partials.field', ['model' => 'values', 'key' => 'meterNumber', 'label' => 'Meter No', 'placeholder' => $chosenType === WorkOrderType::NewInstallation ? 'Planned meter (optional)' : 'Meter number'])
                        </div>

                        <div class="lg:col-start-4 lg:row-start-2">
                            @include('livewire.work-orders.partials.field', ['model' => 'values', 'key' => 'meterTypeId', 'label' => 'Meter Type', 'placeholder' => 'Select Meter Type', 'choices' => $meterTypes, 'hint' => $meterTypeHint])
                        </div>

                        <div class="lg:col-start-3 lg:row-start-3">
                            @include('livewire.work-orders.partials.field', ['model' => 'values', 'key' => 'serialNumber', 'label' => 'Serial No', 'placeholder' => '123-456-7890'])
                        </div>
                    @endif

                    <div class="lg:col-start-4 lg:row-start-3">
                        @include('livewire.work-orders.partials.field', ['model' => 'values', 'key' => 'assignedToId', 'label' => 'Assigned To', 'placeholder' => 'Select User', 'choices' => $assignees, 'required' => true])
                        @if ($assignees === [])
                            <p class="mt-1 text-xs text-placeholder">
                                {{ $assigneeRole ? "No active users have the {$assigneeRole->name} role." : 'No active users yet.' }}
                            </p>
                        @endif
                        @if ($access['edit'])
                            <button type="button" class="mt-1 text-left text-xs text-accent italic underline underline-offset-2 hover:text-ink-strong" wire:click="openRoleChooser">
                                Choose default user role for this field
                            </button>
                        @endif
                        @if ($assigneeRole)
                            <p class="mt-0.5 text-xs text-label">Showing users with the {{ $assigneeRole->name }} role.</p>
                        @endif
                    </div>

                    <div class="sm:col-span-2 lg:col-start-1 lg:row-span-3 lg:row-start-2">
                        @include('livewire.work-orders.partials.field', ['model' => 'values', 'key' => 'description', 'label' => 'Description', 'placeholder' => 'Add notes for our delivery staff', 'rows' => 4])
                    </div>

                    <div class="flex flex-wrap items-end justify-end gap-x-4 gap-y-2 sm:col-span-2 lg:col-start-3 lg:col-span-2 lg:row-start-4">
                        @if ($access['edit'])
                            <button type="button" class="link-button" wire:click="openFees">Set default fees</button>
                        @endif
                        <button type="submit" class="form-button form-button-sm min-w-40" wire:loading.attr="disabled" wire:target="submit">
                            <span wire:loading.remove wire:target="submit">Submit Work Order</span>
                            <span wire:loading wire:target="submit">Submitting…</span>
                        </button>
                    </div>
                </fieldset>
            </form>

            <hr class="mt-5 border-0 border-t border-line">
        @endif

        @if ($overdueCount > 0)
            <x-alert tone="error" class="mt-5 flex items-center gap-2">
                <x-icon name="warning" :size="18" />
                <span>
                    <strong>{{ $overdueCount === 1 ? '1 work order has' : "{$overdueCount} work orders have" }}</strong>
                    been open for more than {{ WorkOrder::OVERDUE_AFTER_DAYS }} days.
                    @unless ($pendingOnly)
                        <button type="button" class="ml-1 underline underline-offset-2" wire:click="$set('pendingOnly', true)">Show pending work orders</button>
                    @endunless
                </span>
            </x-alert>
        @endif

        <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
            <x-search-box label="Search work orders" :width="200" wire:model.live.debounce.300ms="search" />
            <label class="inline-flex items-center gap-2 text-sm font-semibold text-ink-strong">
                <input type="checkbox" wire:model.live="pendingOnly" class="size-4 accent-accent">
                Pending Work Orders
            </label>
        </div>

        <div class="table-scroller mt-3">
            {{-- On narrow screens the Order Type (2nd) and Dates (4th) columns are hidden, filler rows included. --}}
            <table class="data-table max-md:[&_tr>*:nth-child(2)]:hidden max-md:[&_tr>*:nth-child(4)]:hidden">
                <thead>
                    <tr>
                        <th scope="col" class="w-px">No.</th>
                        <th scope="col">Order Type</th>
                        <th scope="col">Customer</th>
                        <th scope="col" class="w-px">Dates</th>
                        <th scope="col" class="cell-center w-px">Status</th>
                        <th scope="col" class="cell-center"><span class="sr-only">Update</span></th>
                        <th scope="col" class="cell-center"><span class="sr-only">View</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($orders as $order)
                        @php($overdue = $order->isOverdue($timezone))
                        <tr
                            wire:key="order-{{ $order->id }}"
                            wire:click="openView({{ $order->id }})"
                            @class(['row-clickable', 'row-selected' => $order->id === ($editing?->id ?? $viewing?->id ?? $completing?->id)])
                        >
                            <td class="whitespace-nowrap tabular-nums">
                                <span class="block font-medium text-ink-strong">{{ $order->order_number }}</span>
                                @if ($order->parent)
                                    <span class="mt-0.5 block text-xs text-label">from {{ $order->parent->order_number }}</span>
                                @endif
                            </td>
                            <td><span class="font-medium text-ink-strong">{{ $order->order_type->label() }}</span></td>
                            <td>
                                <span class="block">{{ $order->customer->first_name }} {{ $order->customer->last_name }}</span>
                                <span class="mt-0.5 block text-xs whitespace-nowrap text-label tabular-nums">Acct No: {{ $order->customer->account_number }}</span>
                            </td>
                            <td class="text-xs whitespace-nowrap tabular-nums">
                                <span @class(['block', 'font-semibold text-danger' => $overdue, 'text-label' => ! $overdue]) @if ($overdue) title="Open for more than {{ WorkOrder::OVERDUE_AFTER_DAYS }} days" @endif>
                                    @if ($overdue)
                                        <x-icon name="warning" :size="12" class="inline align-[-2px]" />
                                    @endif
                                    Requested: {{ Format::shortDateIn($order->requested_at, $timezone) }}
                                    @if ($overdue)
                                        <span class="sr-only">(overdue)</span>
                                    @endif
                                </span>
                                @if ($order->completed_at)
                                    <span class="mt-0.5 block text-label">Done: {{ Format::shortDateIn($order->completed_at, $timezone) }}{{ $order->completedBy ? ' · '.$order->completedBy->name : '' }}</span>
                                @endif
                            </td>
                            <td class="cell-center">
                                <span class="status-pill {{ $order->status->colorClass() }}">{{ $order->status->label() }}</span>
                                @if ($order->review_flag)
                                    @if ($order->flag_resolved_at)
                                        <span class="mx-auto mt-1 block w-max rounded bg-success-bg px-1.5 py-0.5 text-[11px] whitespace-nowrap text-success" title="{{ $order->review_flag->label() }}">Resolved · {{ $order->flag_resolution }}</span>
                                    @else
                                        <span class="mx-auto mt-1 block w-max rounded bg-danger-bg px-1.5 py-0.5 text-[11px] whitespace-nowrap text-danger">{{ $order->review_flag->label() }}</span>
                                    @endif
                                @endif
                            </td>
                            <td class="cell-actions">
                                @if ($order->status->isOpen())
                                    <button type="button" class="icon-button" wire:click.stop="openEdit({{ $order->id }})" aria-label="Update work order {{ $order->order_number }}">
                                        <x-icon name="pencil" :size="14" />
                                    </button>
                                @endif
                            </td>
                            <td class="cell-actions">
                                <button type="button" class="icon-button" wire:click.stop="openView({{ $order->id }})" aria-label="View work order {{ $order->order_number }}">
                                    <x-icon name="document" :size="14" />
                                </button>
                            </td>
                        </tr>
                    @endforeach
                    <x-filler-rows
                        :count="$orders->count()"
                        :minimum="WorkOrdersPage::MINIMUM_ROWS"
                        :columns="7"
                        :text-column="2"
                        :empty-text="trim($search) === '' && ! $pendingOnly ? 'No work orders yet.' : 'No work orders match.'"
                    />
                </tbody>
            </table>
        </div>

        <x-pagination :paginator="$orders" />
    </div>

    @if ($editing)
        @include('livewire.work-orders.partials.edit-modal')
    @endif

    @if ($viewing)
        @include('livewire.work-orders.partials.view-modal')
    @endif

    @if ($completing)
        @include('livewire.work-orders.partials.complete-modal')
    @endif

    @if ($choosingRole)
        @include('livewire.work-orders.partials.role-modal')
    @endif

    @if ($settingFees)
        @include('livewire.work-orders.partials.fees-modal')
    @endif
</x-page>
