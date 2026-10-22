<div class="flex w-full flex-col items-center">
    <x-auth-card
        title="Forgot your password?"
        description="Enter the email address you signed up with and we'll send you a link to reset your password."
    >
        <form wire:submit="sendResetLink" method="post" novalidate class="flex flex-col gap-[18px]">
            @if ($message)
                <x-alert tone="success">{{ $message }}</x-alert>
            @endif
            @if ($devLink)
                <x-dev-email-notice :link="$devLink" label="Open password reset link" />
            @endif

            <x-text-field
                label="Email Address"
                id="email"
                required
                type="email"
                wire:model="email"
                autocomplete="email"
                autofocus
                :error="$errors->first('email')"
            />

            <div class="mt-1 flex flex-col gap-3">
                <button type="submit" class="btn btn-primary w-full" wire:loading.attr="disabled" wire:target="sendResetLink">
                    <span wire:loading.remove wire:target="sendResetLink">Send Reset Link</span>
                    <span wire:loading wire:target="sendResetLink">Sending…</span>
                </button>
                <p class="text-center text-[13px] text-label">
                    <a href="{{ route('login') }}" class="auth-link">Back to login</a>
                </p>
            </div>
        </form>
    </x-auth-card>
</div>
