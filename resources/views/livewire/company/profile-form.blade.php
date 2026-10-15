@php
    $accept = fn (string $kind): string => implode(',', [
        ...array_map(fn (string $extension): string => ".{$extension}", $uploadRules[$kind]['extensions']),
        ...$uploadRules[$kind]['mimeTypes'],
    ]);
    $help = fn (string $kind): array => ["Support file format : {$uploadRules[$kind]['formats']}.", 'File size not exceeding 3mb.'];
    $error = fn (string $field): ?string => $errors->first("values.{$field}") ?: null;
@endphp

<form
    wire:submit="save"
    method="post"
    novalidate
    class="px-4 pt-6 pb-5 sm:px-7 sm:pt-9 sm:pb-7 lg:px-8 lg:pt-12 lg:pb-8"
    x-data
    x-on:focus-first-error.window="$nextTick(() => $el.querySelector('[aria-invalid=true]')?.focus())"
>
    <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,58fr)_minmax(0,42fr)]">
        <section aria-label="Company details" class="flex min-w-0 flex-col gap-[18px] lg:pr-8">
            <x-text-field label="Company Name" id="company-name" wire:model.live.blur="values.companyName" autocomplete="organization" :error="$error('companyName')" />
            <x-text-field label="Company Address" id="company-address" wire:model.live.blur="values.address" placeholder="Add address" autocomplete="street-address" :error="$error('address')" />

            <div class="grid grid-cols-1 items-start gap-[22px] sm:grid-cols-2">
                <x-select-field label="City" id="company-city" placeholder="Select city" :options="$options['cities']" wire:model.live="values.cityId" :error="$error('cityId')" />
                <x-select-field label="State/Province" id="company-province" placeholder="Select province" :options="$options['provinces']" wire:model.live="values.provinceId" :error="$error('provinceId')" />
            </div>

            <div class="grid grid-cols-1 items-start gap-[22px] sm:grid-cols-2">
                <x-text-field label="Zip/Postal code" id="company-zip" wire:model.live.blur="values.zipCode" placeholder="Input code" autocomplete="postal-code" inputmode="numeric" :error="$error('zipCode')" />
                <x-select-field label="Country/Region" id="company-country" placeholder="Select country/region" :options="$options['countries']" wire:model.live="values.countryId" :error="$error('countryId')" />
            </div>

            <x-text-field label="Email Address" id="company-email" type="email" wire:model.live.blur="values.email" autocomplete="email" :error="$error('email')" />

            <div class="grid grid-cols-1 items-start gap-[22px] sm:grid-cols-2">
                <x-text-field label="Tax Identification Number" id="company-tin" wire:model.live.blur="values.taxIdNumber" :error="$error('taxIdNumber')" />
                <x-text-field label="Phone/Telephone Number" id="company-phone" type="tel" wire:model.live.blur="values.phoneNumber" autocomplete="tel" :error="$error('phoneNumber')" />
            </div>

            <div class="grid grid-cols-1 items-start gap-[22px] sm:grid-cols-2">
                <x-text-field label="Company Email Address" id="company-company-email" type="email" wire:model.live.blur="values.companyEmail" :error="$error('companyEmail')" />
                <x-text-field label="Company Page/Site (Optional)" id="company-website" type="url" inputmode="url" wire:model.live.blur="values.website" autocomplete="url" :error="$error('website')" />
            </div>

            <div class="mt-2.5 grid grid-cols-1 items-start gap-6 sm:grid-cols-2 sm:gap-[22px]">
                <x-file-upload
                    label="Company Logo"
                    model="logo"
                    :accept="$accept('logo')"
                    :help="$help('logo')"
                    :preview-url="$previews['logo']['url']"
                    :file-name="$previews['logo']['fileName']"
                    :error="$errors->first('logo') ?: null"
                    clear="clearAsset('logo')"
                />
                <x-file-upload
                    label="Company Icon"
                    model="icon"
                    :accept="$accept('icon')"
                    :help="$help('icon')"
                    :preview-url="$previews['icon']['url']"
                    :file-name="$previews['icon']['fileName']"
                    :error="$errors->first('icon') ?: null"
                    clear="clearAsset('icon')"
                />
            </div>
        </section>

        <section aria-labelledby="point-person-heading" class="mt-8 flex min-w-0 flex-col border-t border-divider pt-8 lg:mt-0 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-8">
            <x-text-field label="Head of Company (Firstname,MI,Lastname)" id="company-head" wire:model.live.blur="values.headOfCompany" autocomplete="off" :error="$error('headOfCompany')" />

            <h2 id="point-person-heading" class="mt-[38px] mb-4 text-sm font-normal text-label italic">Point Person</h2>

            <div class="grid grid-cols-1 items-start gap-x-4 gap-y-3.5 sm:grid-cols-2">
                <x-text-field variant="inset" label="Firstname" id="pp-first-name" wire:model.live.blur="values.pointPersonFirstName" autocomplete="given-name" :error="$error('pointPersonFirstName')" />
                <x-text-field variant="inset" label="Lastname" id="pp-last-name" wire:model.live.blur="values.pointPersonLastName" autocomplete="family-name" :error="$error('pointPersonLastName')" />
                <x-text-field variant="inset" label="Contact Number" id="pp-contact" type="tel" wire:model.live.blur="values.pointPersonContact" autocomplete="tel" :error="$error('pointPersonContact')" />
                <x-text-field variant="inset" label="Email Address" id="pp-email" type="email" wire:model.live.blur="values.pointPersonEmail" autocomplete="email" :error="$error('pointPersonEmail')" />
                <x-text-field variant="inset" label="Employee Number" id="pp-employee-number" wire:model.live.blur="values.employeeNumber" :error="$error('employeeNumber')" />
                <x-text-field variant="inset" label="Employee Title/Position" id="pp-employee-title" wire:model.live.blur="values.employeeTitle" autocomplete="organization-title" :error="$error('employeeTitle')" />
            </div>

            <p class="mt-[22px] text-xs leading-[1.7] text-label italic">
                Note : Point person's name will be used as a contact person to assist and provide support to your clients
                or partners inquiries. It will be placed in all clients profiles such as billings, etc.
            </p>

            <div class="mt-auto flex flex-col-reverse items-stretch gap-4 pt-8 sm:flex-row sm:flex-wrap sm:items-center sm:justify-end">
                @if ($status)
                    <p
                        @class(['flex-[1_1_180px] text-center text-xs sm:text-right', 'text-success' => $status['type'] === 'success', 'text-danger' => $status['type'] === 'error'])
                        role="{{ $status['type'] === 'error' ? 'alert' : 'status' }}"
                    >
                        {{ $status['message'] }}
                    </p>
                @endif

                @if ($canSave)
                    <button
                        type="submit"
                        class="h-12 w-full min-w-[140px] rounded-xs bg-button px-6 text-[13px] text-white transition-colors hover:enabled:bg-button-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent disabled:cursor-default disabled:bg-button-idle disabled:text-[#eef0f2] sm:h-[54px] sm:w-auto"
                        wire:loading.attr="disabled"
                        wire:target="save"
                    >
                        <span wire:loading.remove wire:target="save">Save Profile</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </button>
                @endif
            </div>
        </section>
    </div>
</form>
