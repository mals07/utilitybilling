@use('App\Livewire\Company\OperationSettingsForm')

<form wire:submit="save" novalidate aria-label="Operation settings">
    <x-flash class="mb-4" />

    @include('livewire.company.partials.float-text', ['field' => 'companyHeaderLine', 'label' => 'Company Header Line', 'error' => $errors->first('companyHeaderLine')])

    <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_184px]">
        <div class="flex min-w-0 flex-col gap-2">
            @foreach (['billingHeader1' => 'Billing Header 1', 'billingHeader2' => 'Billing Header 2', 'billingHeader3' => 'Billing Header 3'] as $field => $label)
                @include('livewire.company.partials.float-text', ['error' => $errors->first($field)])
            @endforeach
        </div>

        <div class="flex min-w-0 flex-col gap-2">
            @foreach (['billingFooter1' => 'Billing Footer 1', 'billingFooter2' => 'Billing Footer 2', 'billingFooter3' => 'Billing Footer 3'] as $field => $label)
                @include('livewire.company.partials.float-text', ['error' => $errors->first($field)])
            @endforeach
        </div>

        <div class="flex min-w-0 flex-col gap-2 md:col-span-2 lg:col-span-1">
            <div>
                <label class="float">
                    <span class="float-label">Default # of days for due date</span>
                    <input
                        type="number"
                        min="0"
                        max="{{ OperationSettingsForm::MAX_DUE_DATE_DAYS }}"
                        step="1"
                        inputmode="numeric"
                        wire:model="dueDateDays"
                        placeholder=" "
                        aria-required="true"
                        @disabled(! $canEdit)
                        class="float-control text-right"
                        @error('dueDateDays') aria-invalid="true" aria-describedby="ops-dueDateDays-error" @enderror
                    >
                </label>
                @error('dueDateDays')
                    <p id="ops-dueDateDays-error" class="form-error" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="float">
                    <span class="float-label">Currency</span>
                    <select
                        wire:model="currency"
                        aria-required="true"
                        @disabled(! $canEdit)
                        class="float-control"
                        @error('currency') aria-invalid="true" aria-describedby="ops-currency-error" @enderror
                    >
                        <option value="">Select currency</option>
                        @foreach (OperationSettingsForm::CURRENCIES as $code => $label)
                            <option value="{{ $code }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                @error('currency')
                    <p id="ops-currency-error" class="form-error" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="float">
                    <span class="float-label">Time Zone</span>
                    <select
                        wire:model="timezone"
                        aria-required="true"
                        @disabled(! $canEdit)
                        class="float-control"
                        @error('timezone') aria-invalid="true" aria-describedby="ops-timezone-error" @enderror
                    >
                        <option value="">Select Timezone</option>
                        @foreach ($timezones as $zone)
                            <option value="{{ $zone }}">{{ $zone }}</option>
                        @endforeach
                    </select>
                </label>
                @error('timezone')
                    <p id="ops-timezone-error" class="form-error" role="alert">{{ $message }}</p>
                @enderror
            </div>

            @if ($canEdit)
                <div class="flex justify-end">
                    <button type="submit" class="form-button w-full md:w-[88px]" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">Save</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </button>
                </div>
            @endif
        </div>
    </div>
</form>
