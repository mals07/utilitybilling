{{--
    The Account No. field of App\Livewire\Concerns\SearchesCustomers: type an account number or a
    name, then pick the customer from the list. $chosenCustomer and $customerMatches come from the component.
--}}
@php($fieldId = $fieldId ?? 'customer-search')

<div
    class="relative min-w-0"
    x-data="{ open: false }"
    x-on:click.outside="open = false"
    x-on:keydown.escape="if (open) { open = false; $event.stopPropagation(); }"
>
    <label for="{{ $fieldId }}" class="mb-1 block text-caption font-semibold text-ink-strong">{{ $label ?? 'Account No.' }}<x-required-mark /></label>
    <div class="relative">
        <input
            id="{{ $fieldId }}"
            type="search"
            wire:model.live.debounce.300ms="customerSearch"
            x-on:focus="open = true"
            x-on:input="open = true"
            placeholder="Type an account no. or name"
            autocomplete="off"
            role="combobox"
            aria-required="true"
            aria-autocomplete="list"
            aria-controls="{{ $fieldId }}-matches"
            x-bind:aria-expanded="open.toString()"
            class="form-control !h-[34px] !pr-8 !text-body"
            @error('customerId') aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @enderror
        >
        <x-icon name="search" :size="14" class="pointer-events-none absolute top-1/2 right-2.5 -translate-y-1/2 text-accent" />
    </div>

    @if (trim($customerSearch) !== '' && $chosenCustomer === null)
        <ul
            id="{{ $fieldId }}-matches"
            role="listbox"
            x-show="open"
            class="absolute inset-x-0 top-full z-20 mt-1 max-h-64 overflow-y-auto rounded-xs border border-line bg-card py-1 shadow-lg"
        >
            @forelse ($customerMatches as $match)
                <li wire:key="{{ $fieldId }}-match-{{ $match->id }}" role="option" aria-selected="false">
                    <button
                        type="button"
                        class="flex w-full items-baseline gap-2 px-3 py-1.5 text-left text-body hover:bg-hover focus-visible:bg-hover focus-visible:outline-none"
                        wire:click="chooseCustomer({{ $match->id }})"
                        x-on:click="open = false"
                    >
                        <span class="font-medium text-ink-strong">{{ $match->account_number }}</span>
                        <span class="truncate text-ink-soft">{{ $match->first_name }} {{ $match->last_name }}</span>
                    </button>
                </li>
            @empty
                <li class="px-3 py-1.5 text-body text-placeholder">No customers match.</li>
            @endforelse
        </ul>
    @endif

    @if ($chosenCustomer === null)
        <p class="mt-1 text-caption text-placeholder">Results appear as you type; click a customer to choose them.</p>
    @endif

    @error('customerId')
        <p id="{{ $fieldId }}-error" class="form-error" role="alert">{{ $message }}</p>
    @enderror
</div>
