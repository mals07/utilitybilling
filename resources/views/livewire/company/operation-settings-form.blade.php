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
                    <span class="float-label">Default # of days for due date<x-required-mark /></span>
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
                    <span class="float-label">Currency<x-required-mark /></span>
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
                    <span class="float-label">Time Zone<x-required-mark /></span>
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

        </div>
    </div>

    <div class="mt-5 grid grid-cols-1 gap-3 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        {{-- Tax --}}
        <fieldset class="min-w-0 rounded-xs border border-line bg-card px-4 pt-2 pb-4">
            <legend class="px-1 text-[13px] font-semibold text-ink-strong">Tax</legend>

            <label class="check !text-[13px] !text-ink">
                <input type="checkbox" wire:model.live="taxEnabled" @disabled(! $canEdit)>
                Charge tax on bills (e.g. 12% VAT)
            </label>

            <div @class(['mt-3 grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,1fr)_140px]', 'opacity-60' => ! $taxEnabled])>
                <div>
                    <label class="float">
                        <span class="float-label">Tax name (shown on the bill)<x-required-mark /></span>
                        <input type="text" wire:model="taxName" maxlength="30" aria-required="true" placeholder=" " @disabled(! $canEdit || ! $taxEnabled) class="float-control" @error('taxName') aria-invalid="true" aria-describedby="ops-taxName-error" @enderror>
                    </label>
                    @error('taxName') <p id="ops-taxName-error" class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="float">
                        <span class="float-label">Rate (%)<x-required-mark /></span>
                        <input type="text" inputmode="decimal" wire:model="taxRate" aria-required="true" placeholder=" " @disabled(! $canEdit || ! $taxEnabled) class="float-control text-right" @error('taxRate') aria-invalid="true" aria-describedby="ops-taxRate-error" @enderror>
                    </label>
                    @error('taxRate') <p id="ops-taxRate-error" class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>

            <div @class(['mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2', 'opacity-60' => ! $taxEnabled])>
                <fieldset class="min-w-0">
                    <legend class="mb-1.5 text-xs font-semibold text-ink-strong">Your rates and charges are<x-required-mark /></legend>
                    <label class="check !text-[13px]">
                        <input type="radio" wire:model="taxMode" value="exclusive" @disabled(! $canEdit || ! $taxEnabled) class="check-square">
                        Before tax: add the tax on top
                    </label>
                    <label class="check mt-1 !text-[13px]">
                        <input type="radio" wire:model="taxMode" value="inclusive" @disabled(! $canEdit || ! $taxEnabled) class="check-square">
                        Tax included: only show it on the bill
                    </label>
                </fieldset>
                <fieldset class="min-w-0">
                    <legend class="mb-1.5 text-xs font-semibold text-ink-strong">Tax applies to</legend>
                    <label class="check !text-[13px]"><input type="checkbox" wire:model="taxOnWater" @disabled(! $canEdit || ! $taxEnabled)> Water consumption</label>
                    <label class="check mt-1 !text-[13px]"><input type="checkbox" wire:model="taxOnFees" @disabled(! $canEdit || ! $taxEnabled)> Work order fees</label>
                    <label class="check mt-1 !text-[13px]"><input type="checkbox" wire:model="taxOnSurcharges" @disabled(! $canEdit || ! $taxEnabled)> Overdue surcharges</label>
                </fieldset>
            </div>

            <p class="mt-3 text-xs text-label">
                Also taxed: charge elements ticked <em>Taxable</em> on the Charges page. Customers of an account type ticked <em>Tax exempt</em> below (e.g. Government) are billed without tax.
                Check the rate and what is taxable with your accountant.
            </p>
        </fieldset>

        {{-- Meter reading review --}}
        <fieldset class="min-w-0 rounded-xs border border-line bg-card px-4 pt-2 pb-4">
            <legend class="px-1 text-[13px] font-semibold text-ink-strong">Meter reading review</legend>
            <p class="text-xs text-label">Readings that look wrong wait in <em>Needs review</em> on the Meter Readings page until someone confirms them.</p>

            <div class="mt-3">
                <label class="float">
                    <span class="float-label">Review when usage is above (× 3-month average)<x-required-mark /></span>
                    <input type="text" inputmode="decimal" wire:model="reviewUsageMultiplier" aria-required="true" placeholder=" " @disabled(! $canEdit) class="float-control text-right" @error('reviewUsageMultiplier') aria-invalid="true" aria-describedby="ops-reviewUsageMultiplier-error" @enderror>
                </label>
                @error('reviewUsageMultiplier')
                    <p id="ops-reviewUsageMultiplier-error" class="form-error" role="alert">{{ $message }}</p>
                @else
                    <p class="mt-1 text-xs text-label">2 means double the usual usage. 0 turns this check off.</p>
                @enderror
            </div>

            <label class="check mt-3 !text-[13px]">
                <input type="checkbox" wire:model="reviewZeroUsage" @disabled(! $canEdit)>
                Review readings with no usage
            </label>
        </fieldset>
    </div>

    {{-- Disconnection --}}
    <fieldset class="mt-3 min-w-0 rounded-xs border border-line bg-card px-4 pt-2 pb-4">
        <legend class="px-1 text-[13px] font-semibold text-ink-strong">Disconnection for non-payment</legend>
        <p class="text-xs text-label">Customers with this many overdue bills are listed on the Disconnections page, where staff issue the notices and create the work orders.</p>

        <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-[200px_200px_minmax(0,1fr)]">
            <div>
                <label class="float">
                    <span class="float-label">Flag at # of overdue bills<x-required-mark /></span>
                    <input type="number" min="1" max="{{ OperationSettingsForm::MAX_DISCONNECTION_OVERDUE_BILLS }}" step="1" inputmode="numeric" wire:model="disconnectionOverdueBills" aria-required="true" placeholder=" " @disabled(! $canEdit) class="float-control text-right" @error('disconnectionOverdueBills') aria-invalid="true" aria-describedby="ops-disconnectionOverdueBills-error" @enderror>
                </label>
                @error('disconnectionOverdueBills')
                    <p id="ops-disconnectionOverdueBills-error" class="form-error" role="alert">{{ $message }}</p>
                @else
                    <p class="mt-1 text-xs text-label">Paying until fewer are overdue clears the notice.</p>
                @enderror
            </div>
            <div>
                <label class="float">
                    <span class="float-label">Notice gives # working days<x-required-mark /></span>
                    <input type="number" min="1" max="{{ OperationSettingsForm::MAX_DISCONNECTION_NOTICE_DAYS }}" step="1" inputmode="numeric" wire:model="disconnectionNoticeDays" aria-required="true" placeholder=" " @disabled(! $canEdit) class="float-control text-right" @error('disconnectionNoticeDays') aria-invalid="true" aria-describedby="ops-disconnectionNoticeDays-error" @enderror>
                </label>
                @error('disconnectionNoticeDays')
                    <p id="ops-disconnectionNoticeDays-error" class="form-error" role="alert">{{ $message }}</p>
                @else
                    <p class="mt-1 text-xs text-label">Time to pay before disconnection.</p>
                @enderror
            </div>
            <fieldset class="min-w-0 sm:col-span-2 lg:col-span-1">
                <legend class="mb-1.5 text-xs font-semibold text-ink-strong">Days not counted as working days</legend>
                <div class="flex flex-wrap gap-x-4 gap-y-1">
                    @foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $day => $label)
                        <label class="check !text-[13px]" wire:key="skip-day-{{ $day }}">
                            <input type="checkbox" wire:model="disconnectionSkipDays" value="{{ $day }}" @disabled(! $canEdit)>
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
                @error('disconnectionSkipDays') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                @error('disconnectionSkipDays.*') <p class="form-error" role="alert">{{ $message }}</p> @enderror
            </fieldset>
        </div>
    </fieldset>

    @if ($canEdit)
        <div class="mt-4 flex justify-end">
            <button type="submit" class="form-button w-full md:w-[88px]" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Save</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
        </div>
    @endif
</form>
