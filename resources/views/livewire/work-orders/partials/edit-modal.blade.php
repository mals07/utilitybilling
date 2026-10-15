@use('App\Enums\WorkOrderStatus')
@use('App\Support\Format')

@php($readOnly = ! $access['edit'])

<x-modal :title="'Update Work Order '.$editing->order_number" close="$wire.closeModal()" width="form">
    <form
        wire:submit="update"
        novalidate
        class="mt-3"
        x-data
        x-on:focus-first-error.window="$nextTick(() => $el.querySelector('[aria-invalid=true]')?.focus())"
    >
        @if ($notice)
            <x-alert tone="error" class="mb-3">{{ $notice }}</x-alert>
        @endif

        <p class="text-xs text-label">
            {{ $editing->customer->account_number }} · {{ $editing->customer->first_name }} {{ $editing->customer->last_name }}
            · Requested {{ Format::date($editing->requested_at, $timezone) }}
        </p>

        <fieldset @disabled($readOnly) class="m-0 mt-3 grid min-w-0 grid-cols-1 gap-x-5 gap-y-3 border-0 p-0 min-[560px]:grid-cols-2" wire:loading.attr="disabled" wire:target="update">
            @include('livewire.work-orders.partials.field', ['model' => 'edit', 'key' => 'orderType', 'label' => 'Order Type', 'placeholder' => 'Select Order Type', 'choices' => $orderTypes, 'required' => true])
            @include('livewire.work-orders.partials.field', ['model' => 'edit', 'key' => 'status', 'label' => 'Status', 'placeholder' => 'Select Status', 'choices' => WorkOrderStatus::options(), 'required' => true])
            @include('livewire.work-orders.partials.field', ['model' => 'edit', 'key' => 'meterNumber', 'label' => 'Meter No', 'placeholder' => 'Meter number'])
            @include('livewire.work-orders.partials.field', ['model' => 'edit', 'key' => 'meterTypeId', 'label' => 'Meter Type', 'placeholder' => 'Select Meter Type', 'choices' => $editMeterTypes])
            @include('livewire.work-orders.partials.field', ['model' => 'edit', 'key' => 'serialNumber', 'label' => 'Serial No', 'placeholder' => '123-456-7890'])
            @include('livewire.work-orders.partials.field', ['model' => 'edit', 'key' => 'assignedToId', 'label' => 'Assigned To', 'placeholder' => 'Select User', 'choices' => $editAssignees, 'required' => true])
            <div class="min-[560px]:col-span-2">
                @include('livewire.work-orders.partials.field', ['model' => 'edit', 'key' => 'description', 'label' => 'Description', 'placeholder' => 'Add notes for our delivery staff', 'rows' => 4])
            </div>
        </fieldset>

        @unless ($readOnly)
            <p class="mt-3 text-xs text-label">Setting the status to Completed records you as the one who completed it, with today's date.</p>
            <div class="form-actions mt-4">
                <button type="button" class="text-button" wire:click="closeModal">Cancel</button>
                <button type="submit" class="form-button min-w-[120px]" wire:loading.attr="disabled" wire:target="update">
                    <span wire:loading.remove wire:target="update">Update</span>
                    <span wire:loading wire:target="update">Saving…</span>
                </button>
            </div>
        @endunless
    </form>
</x-modal>
