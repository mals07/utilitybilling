@use('App\Enums\FeeBilling')
@use('App\Support\Billing\WorkOrderBilling')
@use('App\Support\Format')
@use('App\Support\WorkOrderCompletion')

@php
    $type = $completing->order_type;
    $customer = $completing->customer;
    $field = 'livewire.work-orders.partials.field';
@endphp

<x-modal :title="'Complete Work Order '.$completing->order_number" close="$wire.closeModal()" width="form">
    <form
        wire:submit="complete"
        novalidate
        class="mt-3"
        x-data
        x-on:focus-first-error.window="$nextTick(() => $el.querySelector('[aria-invalid=true]')?.focus())"
    >
        <div class="grid gap-1 rounded-xs bg-panel px-3 py-2 text-[13px]">
            <p class="font-semibold text-ink-strong">{{ $type->label() }}</p>
            <p class="text-label">
                {{ $customer->account_number }} · {{ $customer->first_name }} {{ $customer->last_name }}
                · {{ $customer->status->label() }}
                @if ($customer->hasMeter())
                    · Meter {{ $customer->meter_number }}
                @endif
                · Requested {{ Format::date($completing->requested_at, $timezone) }}
            </p>
        </div>

        @error('completion')
            <x-alert tone="error" class="mt-3">{{ $message }}</x-alert>
        @enderror

        <fieldset class="m-0 mt-4 min-w-0 border-0 p-0" wire:loading.attr="disabled" wire:target="complete">
            <legend class="text-[13px] font-semibold text-ink-strong">What was done</legend>
            <div class="mt-2 grid grid-cols-1 gap-x-5 gap-y-3 min-[560px]:grid-cols-2">
                @foreach ($completionForm['fields'] as $key => $spec)
                    @php
                        $label = $spec['label'].($spec['required'] ? '' : ' (optional)');
                        $wide = $spec['kind'] === 'textarea';
                    @endphp
                    <div wire:key="completion-field-{{ $key }}" @class(['min-[560px]:col-span-2' => $wide])>
                        @switch($spec['kind'])
                            @case('select')
                                @include($field, ['model' => 'completion.fields', 'key' => $key, 'label' => $label, 'placeholder' => 'Select', 'choices' => $spec['options'], 'required' => $spec['required'], 'live' => true])
                                @break
                            @case('meter_type')
                                @include($field, ['model' => 'completion.fields', 'key' => $key, 'label' => $label, 'placeholder' => 'Select Meter Type', 'choices' => $completionForm['meterTypes'], 'required' => $spec['required'], 'hint' => 'Add meter types in Company Setup → Operation Setup.'])
                                @break
                            @case('textarea')
                                @include($field, ['model' => 'completion.fields', 'key' => $key, 'label' => $label, 'rows' => 3, 'required' => $spec['required'], 'placeholder' => $spec['hint'] ?? ''])
                                @break
                            @case('date')
                                @include($field, ['model' => 'completion.fields', 'key' => $key, 'label' => $label, 'type' => 'date', 'required' => $spec['required']])
                                @break
                            @case('month')
                                @include($field, ['model' => 'completion.fields', 'key' => $key, 'label' => $label, 'type' => 'month', 'required' => $spec['required']])
                                @break
                            @case('email')
                                @include($field, ['model' => 'completion.fields', 'key' => $key, 'label' => $label, 'type' => 'email', 'maxlength' => $spec['max'] ?? 150, 'required' => $spec['required']])
                                @break
                            @case('phone')
                                @include($field, ['model' => 'completion.fields', 'key' => $key, 'label' => $label, 'type' => 'tel', 'inputmode' => 'tel', 'maxlength' => $spec['max'] ?? 30, 'required' => $spec['required']])
                                @break
                            @case('text')
                                @include($field, ['model' => 'completion.fields', 'key' => $key, 'label' => $label, 'maxlength' => $spec['max'] ?? 100, 'required' => $spec['required'], 'hint' => $spec['hint'] ?? null])
                                @break
                            @default
                                {{-- reading, decimal, amount, latitude, longitude --}}
                                @include($field, ['model' => 'completion.fields', 'key' => $key, 'label' => $label, 'inputmode' => 'decimal', 'maxlength' => 16, 'required' => $spec['required'], 'placeholder' => $spec['kind'] === 'reading' ? '0.000' : '', 'hint' => $spec['hint'] ?? null])
                        @endswitch
                    </div>
                @endforeach
            </div>
        </fieldset>

        @if ($completionForm['checklist'] !== [])
            <fieldset class="m-0 mt-5 min-w-0 border-0 p-0" @error('completion.checked') aria-describedby="completion-checklist-error" @enderror>
                <legend class="text-[13px] font-semibold text-ink-strong">Checklist<x-required-mark /> <span class="font-normal text-label">— tick every item</span></legend>
                <div class="mt-2 grid gap-2 min-[560px]:grid-cols-2">
                    @foreach ($completionForm['checklist'] as $index => $item)
                        <label wire:key="completion-check-{{ $index }}" class="flex items-center gap-2 text-[13px] text-ink">
                            <input type="checkbox" wire:model="completion.checked.{{ $index }}" class="size-4 accent-accent" @error('completion.checked') aria-invalid="true" @enderror>
                            {{ $item }}
                        </label>
                    @endforeach
                </div>
                @error('completion.checked')
                    <p id="completion-checklist-error" class="form-error" role="alert">{{ $message }}</p>
                @enderror
            </fieldset>
        @endif

        @if ($type->allowsPhotos())
            <fieldset class="m-0 mt-5 min-w-0 border-0 p-0">
                <legend class="text-[13px] font-semibold text-ink-strong">
                    Photos<x-required-mark :when="$type->requiresPhoto()" />
                    <span class="font-normal text-label">
                        — {{ $type->requiresPhoto() ? 'at least one required' : 'optional' }}, up to {{ WorkOrderCompletion::MAX_PHOTOS }} (JPG, PNG or WebP, 2MB each)
                    </span>
                </legend>
                <div class="mt-2 flex flex-wrap items-start gap-3">
                    @foreach ($photos as $index => $photo)
                        <div wire:key="completion-photo-{{ $index }}-{{ $photo->getFilename() }}" class="relative">
                            <img src="{{ $photo->temporaryUrl() }}" alt="Photo {{ $index + 1 }}" class="size-24 rounded-xs border border-line object-cover">
                            <button type="button" class="upload-remove absolute -top-2 -right-2" wire:click="removePhoto({{ $index }})" aria-label="Remove photo {{ $index + 1 }}">
                                <x-icon name="close" :size="12" />
                            </button>
                        </div>
                    @endforeach
                    @if (count($photos) < WorkOrderCompletion::MAX_PHOTOS)
                        <label class="flex size-24 cursor-pointer flex-col items-center justify-center gap-1 rounded-xs border border-dashed border-upload-line text-xs text-label hover:bg-hover">
                            <x-icon name="upload" :size="20" />
                            <span wire:loading.remove wire:target="newPhotos">Add photo</span>
                            <span wire:loading wire:target="newPhotos">Uploading…</span>
                            <input type="file" wire:model="newPhotos" multiple accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" class="sr-only" @error('photos') aria-invalid="true" aria-describedby="completion-photos-error" @enderror>
                        </label>
                    @endif
                </div>
                @error('photos')
                    <p id="completion-photos-error" class="form-error" role="alert">{{ $message }}</p>
                @enderror
            </fieldset>
        @endif

        <fieldset class="m-0 mt-5 min-w-0 border-0 p-0" wire:loading.attr="disabled" wire:target="complete">
            <legend class="text-[13px] font-semibold text-ink-strong">Completion</legend>
            <div class="mt-2 grid grid-cols-1 gap-x-5 gap-y-3 min-[560px]:grid-cols-2">
                @include($field, ['model' => 'completion', 'key' => 'completedOn', 'label' => 'Date completed', 'type' => 'date', 'max' => $today, 'required' => true])
                @include($field, ['model' => 'completion', 'key' => 'completedById', 'label' => 'Completed by', 'placeholder' => 'Select User', 'choices' => $completionForm['completedBy'], 'required' => true])
                @include($field, ['model' => 'completion', 'key' => 'fee', 'label' => 'Fee (optional)', 'inputmode' => 'decimal', 'maxlength' => 14, 'placeholder' => '0.00', 'hint' => WorkOrderBilling::billingFor($completing) === FeeBilling::SeparateInvoice
                    ? 'Billed on its own invoice as soon as the order is completed.'
                    : "Added to the customer's next monthly bill."])
                <div class="min-[560px]:col-span-2">
                    @include($field, ['model' => 'completion', 'key' => 'remarks', 'label' => 'Remarks (optional)', 'rows' => 2])
                </div>
            </div>
        </fieldset>

        <div class="form-actions mt-5">
            <button type="button" class="text-button" wire:click="closeModal">Cancel</button>
            <button type="submit" class="form-button min-w-[160px]" wire:loading.attr="disabled" wire:target="complete,newPhotos">
                <span wire:loading.remove wire:target="complete">Complete Work Order</span>
                <span wire:loading wire:target="complete">Completing…</span>
            </button>
        </div>
    </form>
</x-modal>
