@use('App\Enums\LookupType')

<div class="mx-auto max-w-[1400px] px-4 pt-4 pb-8 sm:px-6 sm:pt-5 sm:pb-10">
    <div class="mb-2.5 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-lg font-bold text-ink sm:text-xl">Company Setup</h1>
        <button type="button" class="link-button inline-flex items-center gap-1.5 text-sm" x-data x-on:click="$dispatch('guide-start', 'company')">
            <x-icon name="document" :size="16" />
            Guide me
        </button>
    </div>

    @if ($onboarding)
        <x-alert :tone="$justVerified ? 'success' : 'info'" class="mb-3">
            @if ($justVerified) Your email address is verified. @endif
            Set up your company profile to start using Utility Billing. Fill in the required fields below and click
            <strong>Save Profile</strong>.
        </x-alert>
    @endif

    <div
        class="bg-card pb-2 sm:px-3.5 sm:pb-3.5 sm:shadow-card"
        x-data="{
            tab: @js($initialTab),
            tabs: ['profile', 'operation-setup', 'linked-accounts'],
            select(tab) {
                this.tab = tab;
                // Keeps the open tab in the URL (?tab=…) without a navigation.
                const url = new URL(window.location.href);
                if (tab === 'profile') url.searchParams.delete('tab');
                else url.searchParams.set('tab', tab);
                window.history.replaceState(window.history.state, '', url);
            },
            move(index) {
                const next = this.tabs[(index + this.tabs.length) % this.tabs.length];
                this.select(next);
                this.$refs['tab-' + next].focus();
            },
        }"
    >
        <div class="tab-scroller">
            <div role="tablist" aria-label="Company sections" class="tab-list">
                @foreach (['profile' => 'Company Profile', 'operation-setup' => 'Operation Setup', 'linked-accounts' => 'Linked Accounts'] as $id => $label)
                    <button
                        type="button"
                        role="tab"
                        id="company-tab-{{ $id }}"
                        x-ref="tab-{{ $id }}"
                        aria-controls="company-panel-{{ $id }}"
                        x-bind:aria-selected="(tab === @js($id)).toString()"
                        x-bind:tabindex="tab === @js($id) ? 0 : -1"
                        aria-selected="{{ $initialTab === $id ? 'true' : 'false' }}"
                        class="tab"
                        x-on:click="select(@js($id))"
                        x-on:keydown.arrow-right.prevent="move({{ $loop->index + 1 }})"
                        x-on:keydown.arrow-left.prevent="move({{ $loop->index - 1 }})"
                        x-on:keydown.home.prevent="move(0)"
                        x-on:keydown.end.prevent="move(2)"
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Inactive panels stay mounted so unsaved form input survives tab switches. --}}
        <div
            role="tabpanel"
            id="company-panel-profile"
            aria-labelledby="company-tab-profile"
            x-show="tab === 'profile'"
            @if ($initialTab !== 'profile') x-cloak @endif
            class="bg-panel"
        >
            <livewire:company.profile-form :onboarding="$onboarding" :can-save="$canSave" />
        </div>

        <div
            role="tabpanel"
            id="company-panel-operation-setup"
            aria-labelledby="company-tab-operation-setup"
            x-show="tab === 'operation-setup'"
            @if ($initialTab !== 'operation-setup') x-cloak @endif
            class="bg-panel px-4 py-5 sm:px-6"
        >
            <h2 class="sr-only">Operation Setup</h2>
            @if ($onboarding)
                <p class="text-[13px] text-label">Save your company profile first to set up operations.</p>
            @else
                <livewire:company.operation-settings-form />

                <hr class="my-5 border-0 border-t-2 border-line">

                <div class="grid grid-cols-1 gap-x-4 gap-y-8 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach (LookupType::cases() as $type)
                        <livewire:company.lookup-table :type="$type->value" :key="$type->value" />
                    @endforeach
                </div>
            @endif
        </div>

        <div
            role="tabpanel"
            id="company-panel-linked-accounts"
            aria-labelledby="company-tab-linked-accounts"
            x-show="tab === 'linked-accounts'"
            @if ($initialTab !== 'linked-accounts') x-cloak @endif
            class="bg-panel"
        >
            <section class="flex min-h-60 flex-col gap-2 px-4 py-6 sm:min-h-[420px] sm:px-8 sm:py-12">
                <h2 class="text-base font-semibold text-ink">Linked Accounts</h2>
                <p class="text-[13px] text-label">This section is coming soon.</p>
            </section>
        </div>
    </div>
</div>
