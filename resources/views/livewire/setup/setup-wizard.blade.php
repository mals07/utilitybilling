@use('App\Enums\LookupType')

<div class="mx-auto flex max-w-[1600px] flex-col gap-5 px-4 py-5 lg:flex-row lg:items-start lg:gap-6 lg:px-6 lg:py-6">
    {{-- Step list (a progress bar on small screens) --}}
    <nav aria-label="Setup steps" class="shrink-0 lg:sticky lg:top-22 lg:w-56">
        <div class="lg:hidden">
            <p class="text-body font-medium text-ink-soft">Step {{ $number }} of {{ count($steps) }}</p>
            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-table-line">
                <div class="h-full bg-button" style="width: {{ round($number / count($steps) * 100) }}%"></div>
            </div>
        </div>

        <ol class="hidden overflow-hidden rounded-xl border border-table-line bg-card py-2 shadow-card lg:block">
            @foreach ($steps as $item)
                <li wire:key="nav-{{ $item['key'] }}">
                    @if ($item['state'] === 'done')
                        <button type="button" wire:click="goTo('{{ $item['key'] }}')" class="setup-nav-item" data-state="done">
                            <span class="setup-nav-mark"><x-icon name="check" :size="14" /></span>
                            {{ $item['nav'] }}
                        </button>
                    @else
                        <span class="setup-nav-item" data-state="{{ $item['state'] }}" @if ($item['state'] === 'current') aria-current="step" @endif>
                            <span class="setup-nav-mark">{{ $item['number'] }}</span>
                            {{ $item['nav'] }}
                        </span>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>

    <section aria-labelledby="setup-step-title" class="min-w-0 flex-1">
        <div class="rounded-xl border border-table-line bg-card px-4 py-5 shadow-card sm:px-6">
            <p class="text-caption font-medium tracking-wide text-label uppercase">
                Step {{ $number }} of {{ count($steps) }} &middot; {{ $required ? 'Required' : 'Optional' }}
            </p>
            <h1 id="setup-step-title" class="mt-1 text-title font-bold text-ink-strong">{{ $current['title'] }}</h1>
            <p class="mt-1 text-body leading-6 text-ink-soft">{{ $current['intro'] }}</p>

            {{-- Starts the step's guide the first time the step is opened in this browser; "Show me how" runs it again. --}}
            <div
                wire:key="guide-{{ $step }}"
                x-data
                x-init="
                    const seen = 'ub-guide-seen-' + @js($guide);
                    let first = false;
                    try { first = ! localStorage.getItem(seen); localStorage.setItem(seen, '1'); } catch {}
                    if (first) setTimeout(() => $dispatch('guide-start', @js($guide)), 700);
                "
                class="mt-3"
            >
                <button type="button" class="form-button form-button-sm inline-flex items-center gap-1.5" x-on:click="$dispatch('guide-start', @js($guide))">
                    <x-icon name="document" :size="16" />
                    Show me how, step by step
                </button>
            </div>

            @if ($justVerified && $step === 'profile')
                <x-alert tone="success" class="mt-3">Your email address is verified. Let's set up your company.</x-alert>
            @endif
            @if ($step === 'profile' && ! $company)
                <x-alert class="mt-3">
                    These steps set up everything your company needs to bill customers. Steps 1–5 are required before you can use the system;
                    the rest walk you through your first customer and payment. Click <strong>Finish later</strong> at any time; you'll continue from here when you sign in again.
                </x-alert>
            @endif
        </div>

        <div class="mt-4 xl:hidden">
            <details class="group" @if ($step === 'profile') open @endif>
                <summary class="cursor-pointer rounded-xl border border-table-line bg-card px-4 py-3 shadow-card text-body font-semibold text-ink-strong">How to do this step, with an example</summary>
                @include('livewire.setup.partials.guide')
            </details>
        </div>

        <div class="setup-embed mt-4 overflow-hidden rounded-xl border border-table-line bg-card shadow-card">
            @switch($step)
                @case('profile')
                    <livewire:company.profile-form :onboarding="! $company" :can-save="true" key="setup-profile" />
                    @break
                @case('billing')
                    <div class="bg-panel px-4 py-5 sm:px-6">
                        <livewire:company.operation-settings-form key="setup-billing" />
                    </div>
                    @break
                @case('lists')
                    <div class="grid grid-cols-1 gap-5 bg-panel px-4 py-5 sm:grid-cols-2 sm:px-6 2xl:grid-cols-4">
                        @foreach (LookupType::cases() as $type)
                            <livewire:company.lookup-table :type="$type->value" :key="'setup-'.$type->value.'-'.$exampleRun" />
                        @endforeach
                    </div>
                    @break
                @case('rates')
                    <livewire:rates.rates-page :key="'setup-rates-'.$exampleRun" />
                    @break
                @case('charges')
                    <livewire:charges.charges-page :key="'setup-charges-'.$exampleRun" />
                    @break
                @case('team')
                    <livewire:roles.roles-page :key="'setup-roles-'.$exampleRun" />
                    <div class="border-t border-table-line">
                        <livewire:users.users-page key="setup-users" />
                    </div>
                    @break
                @case('customer')
                    <livewire:customers.customers-page key="setup-customer" />
                    @break
                @case('work-order')
                    <livewire:work-orders.work-orders-page key="setup-work-order" />
                    @break
                @case('complete-order')
                    <livewire:work-orders.work-orders-page key="setup-complete-order" />
                    @break
                @case('payment')
                    <livewire:payments.payments-page key="setup-payment" />
                    @break
            @endswitch
        </div>

        <div class="sticky bottom-0 z-20 mt-4 rounded-xl border border-table-line bg-card/95 px-4 py-3 shadow-dialog backdrop-blur sm:px-6">
            @if ($error)
                <p class="mb-2 text-body text-danger" role="alert">{{ $error }}</p>
            @elseif ($stepDone && $company)
                <p class="mb-2 flex items-center gap-1.5 text-body text-success" role="status">
                    <x-icon name="check" :size="16" />
                    This step is done. Click {{ $isLast ? 'Finish setup' : 'Continue' }} to go on.
                </p>
            @endif

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    @if ($number > 1)
                        <button type="button" class="text-button text-body" wire:click="back">&larr; Back</button>
                    @endif
                </div>

                <div class="flex flex-wrap items-center justify-end gap-3">
                    @unless ($required)
                        <button
                            type="button"
                            class="link-button text-body"
                            wire:click="skip"
                            @if ($step !== 'team') wire:confirm="Skip the rest and finish setup? You can add customers, work orders and payments any time from the sidebar." @endif
                        >
                            {{ $step === 'team' ? 'Skip this step' : ($isLast ? 'Skip and finish' : 'Skip the rest and finish') }}
                        </button>
                    @endunless

                    @if ($company)
                        <button type="button" class="form-button" wire:click="continue" wire:loading.attr="disabled" wire:target="continue">
                            {{ $isLast ? 'Finish setup' : 'Continue' }} &rarr;
                        </button>
                    @else
                        <span class="text-body text-label">Save your profile to continue.</span>
                    @endif
                </div>
            </div>
        </div>
    </section>

    <aside aria-label="Step guide" class="hidden w-80 shrink-0 xl:sticky xl:top-22 xl:block">
        @include('livewire.setup.partials.guide')
    </aside>
</div>
