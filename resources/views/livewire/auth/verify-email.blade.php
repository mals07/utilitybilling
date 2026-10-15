<div class="flex w-full flex-col items-center">
    <x-auth-card title="Verify your email address">
        <x-slot:description>
            We sent a verification link to <strong>{{ $email }}</strong>. Open the link to activate your account,
            then you'll set up your company profile.
        </x-slot:description>

        @if ($invalidLink)
            <x-alert tone="error">That verification link is invalid or has expired. Request a new one below.</x-alert>
        @endif

        @if ($devLink)
            <x-dev-email-notice :link="$devLink" label="Verify my email now" />
        @endif

        <form wire:submit="resend" class="flex flex-col gap-[18px]">
            @if ($status)
                <x-alert :tone="$status['tone']">{{ $status['text'] }}</x-alert>
            @endif
            <button type="submit" class="btn btn-primary w-full" wire:loading.attr="disabled" wire:target="resend">
                <span wire:loading.remove wire:target="resend">Resend Verification Email</span>
                <span wire:loading wire:target="resend">Sending…</span>
            </button>
        </form>

        <form method="POST" action="{{ route('logout') }}" class="text-center text-[13px] text-label">
            @csrf
            Wrong account?
            <button type="submit" class="btn btn-link">Log out</button>
        </form>
    </x-auth-card>
</div>
