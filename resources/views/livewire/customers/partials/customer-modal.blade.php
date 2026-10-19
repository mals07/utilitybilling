@use('App\Enums\CustomerStatus')
@use('App\Enums\Page')

@php
    $updating = ! $adding;
    $setupHint = 'Add these in Company Setup → Operation Setup.';
    $statuses = collect(CustomerStatus::cases())->mapWithKeys(fn (CustomerStatus $status) => [$status->value => $status->label()])->all();
@endphp

<x-modal :title="$updating ? 'Update Customer' : 'Add Customer'" close="$wire.closeModal()" width="form">
    {{-- The modal's sections sit on white panels separated by gray bands, as in the design. --}}
    <form
        wire:submit="save"
        novalidate
        class="-mx-5 mt-3 -mb-5 bg-panel sm:-mx-6"
        x-data
        x-on:focus-first-error.window="$nextTick(() => $el.querySelector('[aria-invalid=true]')?.focus())"
    >
        @if ($notice)
            <x-alert tone="error" class="mx-5 mt-3 sm:mx-6">{{ $notice }}</x-alert>
        @endif
        @if ($updating && $selected)
            <p class="px-5 pt-2.5 text-xs text-label sm:px-6">
                Account Number: {{ $selected->account_number }}
                ·
                @if ($selected->hasMeter())
                    Meter No: {{ $selected->meter_number }} · Serial No: {{ $selected->meter_serial_number ?? '—' }}
                    @if ($selected->meter_installed_on)
                        · Installed {{ $selected->meter_installed_on->format('m/d/Y') }}
                    @endif
                @else
                    No meter installed yet
                @endif
                <span class="block text-placeholder">The meter is set by completing a New Installation or Meter Replacement work order.</span>
                @if (auth()->user()->canAccessPage(Page::Invoices) || auth()->user()->canAccessPage(Page::Payments))
                    <a href="{{ route('customers.statement', $selected) }}" target="_blank" rel="noopener" class="link-button mt-1 inline-block">Statement of account</a>
                @endif
            </p>
        @endif

        <fieldset @disabled($readOnly) class="m-0 min-w-0 border-0 p-0" wire:loading.attr="disabled" wire:target="save">
            <section class="bg-card px-5 pt-4 pb-5 sm:px-6">
                <div class="grid grid-cols-1 items-start gap-x-5 gap-y-3 min-[560px]:grid-cols-2">
                    <div class="flex flex-col items-center gap-1.5" x-data="photoCropper({ model: 'photo' })">
                        <button
                            type="button"
                            class="flex size-[140px] shrink-0 items-center justify-center overflow-hidden rounded-xs bg-[#e9e9ee] p-0 hover:enabled:bg-[#e1e1e8] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-line-focus disabled:cursor-default min-[560px]:mt-[18px] min-[560px]:size-40 @error('photo') outline outline-danger @enderror"
                            x-on:click="$refs.file.click()"
                            aria-label="{{ $photoUrl ? 'Change photo' : 'Upload photo' }}"
                            @error('photo') aria-describedby="customer-photo-error" @enderror
                        >
                            <span x-show="uploading" class="text-xs text-muted">Uploading…</span>
                            <span x-show="! uploading" class="contents">
                                @if ($photoUrl)
                                    <img src="{{ $photoUrl }}" alt="" class="size-full object-cover">
                                @else
                                    {{-- Person silhouette with an upload arrow --}}
                                    <svg width="56" height="56" viewBox="0 0 56 56" aria-hidden="true" focusable="false">
                                        <circle cx="28" cy="18" r="10" fill="#b9b6cc" />
                                        <path d="M8 54c0-12 9-20 20-20s20 8 20 20Z" fill="#b9b6cc" />
                                        <rect x="16" y="22" width="24" height="22" rx="3" fill="#ffffff" stroke="#1f2328" stroke-width="2" />
                                        <path d="M28 39V28m-5 4.5 5-5 5 5" fill="none" stroke="#1f2328" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                @endif
                            </span>
                        </button>
                        <input x-ref="file" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" hidden x-on:change="choose($event.target.files[0])">

                        @if ($photoUrl && ! $readOnly)
                            <div class="flex gap-4">
                                <button type="button" class="link-button" x-on:click="adjust(@js($photoUrl))">Adjust photo</button>
                                <button type="button" class="link-button" wire:click="removePhoto" x-on:click="forget()">Remove photo</button>
                            </div>
                        @endif
                        <p class="form-error" role="alert" x-show="clientError" x-text="clientError"></p>
                        @error('photo')
                            <p id="customer-photo-error" class="form-error" role="alert">{{ $message }}</p>
                        @enderror

                        {{-- Adjust Photo: drag to centre the face and zoom, then save the framed square. --}}
                        <div wire:ignore>
                            <div
                                x-show="cropping"
                                x-cloak
                                class="fixed inset-0 z-[70] overflow-y-auto bg-overlay"
                                x-on:click.self="cancel()"
                                x-on:keydown.escape.prevent.stop="cancel()"
                            >
                                <template x-if="cropping">
                                    <div role="dialog" aria-modal="true" aria-labelledby="crop-title" class="modal-dialog" data-width="sm" x-trap.noscroll="cropping">
                                        <div class="modal-content">
                                            <div class="flex items-start justify-between gap-3">
                                                <h2 id="crop-title" class="text-base font-bold text-ink-strong">Adjust Photo</h2>
                                                <button type="button" class="modal-close" x-on:click="cancel()" aria-label="Close">
                                                    <x-icon name="close" />
                                                </button>
                                            </div>

                                            <div class="mt-3 grid justify-items-center gap-3">
                                                <p class="justify-self-stretch text-[13px] text-label">Drag to centre the face. Use the slider or scroll to zoom.</p>
                                                <div
                                                    class="relative shrink-0 cursor-grab touch-none overflow-hidden rounded-xs bg-[#e9e9ee] select-none active:cursor-grabbing focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-line-focus"
                                                    x-bind:style="`width: ${frame}px; height: ${frame}px`"
                                                    tabindex="0"
                                                    role="application"
                                                    aria-label="Photo position. Use the arrow keys to move and plus or minus to zoom."
                                                    x-on:pointerdown="pointerDown($event)"
                                                    x-on:pointermove="pointerMove($event)"
                                                    x-on:pointerup="pointerUp($event)"
                                                    x-on:pointercancel="pointerUp($event)"
                                                    x-on:wheel.prevent="wheel($event)"
                                                    x-on:keydown="key($event)"
                                                >
                                                    <img
                                                        x-ref="image"
                                                        x-bind:src="src"
                                                        alt=""
                                                        draggable="false"
                                                        class="pointer-events-none absolute top-1/2 left-1/2 max-w-none"
                                                        x-bind:style="imageStyle"
                                                        x-on:load="loaded($event)"
                                                        x-on:error="error = `&quot;${fileName}&quot; could not be read as an image.`"
                                                    >
                                                </div>

                                                <label class="flex items-center gap-3 justify-self-stretch text-[13px] text-label">
                                                    <span>Zoom</span>
                                                    <input
                                                        type="range"
                                                        min="1"
                                                        x-bind:max="maxZoom"
                                                        step="0.01"
                                                        x-bind:value="view.zoom"
                                                        x-bind:disabled="! size"
                                                        x-on:input="setZoom($event.target.value)"
                                                        class="flex-1 accent-button"
                                                    >
                                                </label>

                                                <p class="form-error justify-self-stretch" role="alert" x-show="error" x-text="error"></p>

                                                <div class="form-actions justify-self-stretch">
                                                    <button type="button" class="text-button" x-on:click="cancel()">Cancel</button>
                                                    <button type="button" class="form-button" x-on:click="apply()" x-bind:disabled="! size || saving">Apply</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-2">
                        @include('livewire.customers.partials.input', ['key' => 'firstName', 'label' => 'First name', 'placeholder' => 'First name', 'required' => true, 'autofocus' => true])
                        @include('livewire.customers.partials.input', ['key' => 'lastName', 'label' => 'Last name', 'placeholder' => 'Last name', 'required' => true])
                        @include('livewire.customers.partials.input', ['key' => 'companyName', 'label' => 'Company (Optional)', 'placeholder' => 'Company'])
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-x-5 gap-y-3.5 min-[560px]:grid-cols-2">
                    @include('livewire.customers.partials.input', ['key' => 'email', 'label' => 'Email', 'placeholder' => 'Email', 'type' => 'email'])
                    @include('livewire.customers.partials.input', ['key' => 'phoneNumber', 'label' => 'Phone number', 'placeholder' => 'Phone number', 'type' => 'tel', 'inputmode' => 'tel'])
                    @include('livewire.customers.partials.select', ['key' => 'accountTypeId', 'label' => 'Account Type', 'placeholder' => 'Select Account Type', 'choices' => $options['accountTypes'], 'hint' => $setupHint])
                    @include('livewire.customers.partials.select', ['key' => 'status', 'label' => 'Status', 'placeholder' => 'Select Status', 'choices' => $statuses])
                    @include('livewire.customers.partials.select', ['key' => 'chargeTypeId', 'label' => 'Charges Type', 'placeholder' => 'Select Charge Type', 'choices' => $options['chargeTypes'], 'hint' => 'Add charge types on the Charges page.'])
                    @include('livewire.customers.partials.select', ['key' => 'rateTypeId', 'label' => 'Rates', 'placeholder' => 'Select Rate', 'choices' => $options['rateTypes'], 'hint' => 'Add rate types on the Rates page.'])
                    @include('livewire.customers.partials.select', ['key' => 'clusterTypeId', 'label' => 'Cluster Type', 'placeholder' => 'Select Cluster Type', 'choices' => $options['clusterTypes'], 'hint' => $setupHint])
                </div>
            </section>

            @include('livewire.customers.partials.address', ['prefix' => 'home', 'title' => 'Home address', 'locations' => $homeLocations])
            @include('livewire.customers.partials.address', ['prefix' => 'billing', 'title' => 'Billing address', 'locations' => $billingLocations])
        </fieldset>

        @unless ($readOnly)
            <div class="mt-2 flex justify-end bg-card px-5 py-4 sm:px-6">
                <button type="submit" class="form-button min-w-[120px]" wire:loading.attr="disabled" wire:target="save,photo">
                    <span wire:loading.remove wire:target="save">{{ $updating ? 'Update' : 'Submit' }}</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </button>
            </div>
        @endunless
    </form>
</x-modal>
