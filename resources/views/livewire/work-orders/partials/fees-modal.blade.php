@use('App\Enums\FeeBilling')
@use('App\Enums\WorkOrderType')

<x-modal title="Work Order Fees" close="$wire.closeFees()" width="form">
    <form
        wire:submit="saveFees"
        novalidate
        class="mt-3"
        x-data
        x-on:focus-first-error.window="$nextTick(() => $el.querySelector('[aria-invalid=true]')?.focus())"
    >
        <p class="text-body text-label">
            The fee is prefilled when an order of that type is completed (it can still be changed there); leave it blank for no fee.
            <strong class="font-semibold text-ink">Separate invoice</strong> bills the fee as soon as the order is completed, to be paid upfront;
            <strong class="font-semibold text-ink">Next monthly bill</strong> adds it as a line on the customer's next water bill.
        </p>

        <div class="mt-3 grid max-h-[60vh] gap-2 overflow-y-auto pr-1">
            <div class="grid grid-cols-[1fr_8rem_11rem] gap-3 text-caption font-semibold text-ink-strong" aria-hidden="true">
                <span>Order type</span><span class="text-right">Fee</span><span>Billed on</span>
            </div>
            @foreach (WorkOrderType::cases() as $type)
                @php($id = "work-order-fee-{$type->value}")
                <div wire:key="{{ $id }}" class="grid grid-cols-[1fr_8rem_11rem] items-start gap-3">
                    <label for="{{ $id }}" class="pt-1.5 text-body text-ink">{{ $type->label() }}</label>
                    <div>
                        <input
                            id="{{ $id }}"
                            type="text"
                            inputmode="decimal"
                            maxlength="14"
                            placeholder="0.00"
                            wire:model="fees.{{ $type->value }}"
                            class="form-control !h-[32px] text-right !text-body"
                            @error("fees.{$type->value}") aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror
                        >
                        @error("fees.{$type->value}")
                            <p id="{{ $id }}-error" class="form-error" role="alert">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <select
                            aria-label="How the {{ $type->label() }} fee is billed"
                            wire:model="feeBilling.{{ $type->value }}"
                            class="form-control !h-[32px] !text-body"
                            @error("feeBilling.{$type->value}") aria-invalid="true" @enderror
                        >
                            @foreach (FeeBilling::cases() as $billing)
                                <option value="{{ $billing->value }}">{{ $billing->label() }}</option>
                            @endforeach
                        </select>
                        @error("feeBilling.{$type->value}")
                            <p class="form-error" role="alert">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            @endforeach
        </div>

        <div class="form-actions mt-5">
            <button type="button" class="text-button" wire:click="closeFees">Cancel</button>
            <button type="submit" class="form-button min-w-[100px]" wire:loading.attr="disabled" wire:target="saveFees">Save</button>
        </div>
    </form>
</x-modal>
