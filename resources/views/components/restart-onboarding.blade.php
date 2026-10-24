{{-- Temporary, local only: start the setup wizard again as a brand-new account (App\Http\Controllers\RestartOnboardingController). --}}
@if (app()->isLocal())
    <form
        method="POST"
        action="{{ route('dev.restart-onboarding') }}"
        x-data
        x-on:submit="
            if (! confirm('Sign in as a brand-new test account and start onboarding from step 1? (Local testing only)')) { $event.preventDefault(); return; }
            try { Object.keys(localStorage).filter((key) => key.startsWith('ub-guide-seen-')).forEach((key) => localStorage.removeItem(key)); } catch {}
        "
    >
        @csrf
        <button type="submit" {{ $attributes->class('flex items-center gap-2 text-body text-warning hover:underline') }} title="Restart onboarding (test)">
            <x-icon name="restore" :size="18" />
            <span class="lg:sidebar-collapsed:sr-only">Restart onboarding (test)</span>
        </button>
    </form>
@endif
