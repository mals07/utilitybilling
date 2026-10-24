@use('App\Enums\WorkOrderStatus')
@use('App\Support\Format')

@php
    $readOnly = ! $access['edit'];
    $onHold = $editing->status === WorkOrderStatus::OnHold;
@endphp

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

        <div class="grid gap-1 rounded-xs bg-panel px-3 py-2 text-body">
            <p>
                <span class="font-semibold text-ink-strong">{{ $editing->order_type->label() }}</span>
                · <span class="{{ $editing->status->colorClass() }}">{{ $editing->status->label() }}</span>
            </p>
            <p class="text-label">
                {{ $editing->customer->account_number }} · {{ $editing->customer->first_name }} {{ $editing->customer->last_name }}
                · Requested {{ Format::date($editing->requested_at, $timezone) }}
            </p>
            @if ($onHold && $editing->hold_reason)
                <p class="text-warning">On hold: {{ $editing->hold_reason }}</p>
            @endif
        </div>

        <fieldset @disabled($readOnly) class="m-0 mt-3 grid min-w-0 grid-cols-1 gap-x-5 gap-y-3 border-0 p-0 min-[560px]:grid-cols-2" wire:loading.attr="disabled" wire:target="update">
            @unless ($editing->order_type->isOfficeWork())
                @include('livewire.work-orders.partials.field', ['model' => 'edit', 'key' => 'meterNumber', 'label' => 'Meter No', 'placeholder' => 'Meter number'])
                @include('livewire.work-orders.partials.field', ['model' => 'edit', 'key' => 'meterTypeId', 'label' => 'Meter Type', 'placeholder' => 'Select Meter Type', 'choices' => $editMeterTypes])
                @include('livewire.work-orders.partials.field', ['model' => 'edit', 'key' => 'serialNumber', 'label' => 'Serial No', 'placeholder' => '123-456-7890'])
            @endunless
            @include('livewire.work-orders.partials.field', ['model' => 'edit', 'key' => 'assignedToId', 'label' => 'Assigned To', 'placeholder' => 'Select User', 'choices' => $editAssignees, 'required' => true])
            <div class="min-[560px]:col-span-2">
                @include('livewire.work-orders.partials.field', ['model' => 'edit', 'key' => 'description', 'label' => 'Description', 'placeholder' => 'Add notes for our delivery staff', 'rows' => 4])
            </div>
        </fieldset>

        @unless ($readOnly)
            <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap gap-2">
                    @if ($onHold)
                        <button type="button" class="text-button" wire:click="resume">Resume</button>
                    @else
                        <button type="button" class="text-button" wire:click="askReason('hold')">Put On Hold</button>
                    @endif
                    <button type="button" class="text-button text-danger" wire:click="askReason('cancel')">Cancel Order</button>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="text-button" wire:loading.attr="disabled" wire:target="update">
                        <span wire:loading.remove wire:target="update">Save Changes</span>
                        <span wire:loading wire:target="update">Saving…</span>
                    </button>
                    <button type="button" class="form-button min-w-[120px]" wire:click="openComplete({{ $editing->id }})">Complete…</button>
                </div>
            </div>
        @endunless
    </form>
</x-modal>

@if ($reasonFor !== '')
    <x-modal :title="$reasonFor === 'hold' ? 'Put Work Order On Hold' : 'Cancel Work Order'" close="$wire.closeReason()" width="sm">
        <form wire:submit="confirmReason" class="mt-3" novalidate>
            <label for="work-order-reason" class="mb-1 block text-caption font-semibold text-ink-strong">
                {{ $reasonFor === 'hold' ? 'Why is it on hold?' : 'Why is it being cancelled?' }}<x-required-mark />
            </label>
            <textarea
                id="work-order-reason"
                aria-required="true"
                wire:model="reason"
                rows="3"
                maxlength="255"
                autofocus
                placeholder="{{ $reasonFor === 'hold' ? 'e.g. Waiting for materials' : 'e.g. Customer withdrew the request' }}"
                class="form-control !text-body"
                @error('reason') aria-invalid="true" aria-describedby="work-order-reason-error" @enderror
            ></textarea>
            @error('reason')
                <p id="work-order-reason-error" class="form-error" role="alert">{{ $message }}</p>
            @enderror
            @if ($reasonFor === 'cancel')
                <p class="mt-2 text-caption text-label">A cancelled order is final and can't be reopened.</p>
            @endif

            <div class="form-actions mt-4">
                <button type="button" class="text-button" wire:click="closeReason">Back</button>
                <button type="submit" class="form-button min-w-[100px]" wire:loading.attr="disabled" wire:target="confirmReason">
                    {{ $reasonFor === 'hold' ? 'Put On Hold' : 'Cancel Order' }}
                </button>
            </div>
        </form>
    </x-modal>
@endif
