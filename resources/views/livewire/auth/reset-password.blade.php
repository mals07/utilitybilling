<div class="flex w-full flex-col items-center">
    @if (! $linkValid)
        <x-auth-card title="Reset link expired">
            <x-alert tone="error">
                This password reset link is invalid or has expired. Reset links can only be used once and expire after 1 hour.
            </x-alert>
            <p class="text-center text-body text-label">
                <a href="{{ route('password.request') }}" class="auth-link">Request a new reset link</a>
            </p>
        </x-auth-card>
    @else
        <x-auth-card title="Choose a new password" description="You'll be signed out of all devices after the change.">
            <form wire:submit="resetPassword" method="post" novalidate class="flex flex-col gap-[18px]">
                @if ($message)
                    <x-alert tone="error">
                        {{ $message }} <a href="{{ route('password.request') }}">Request a new link</a>
                    </x-alert>
                @endif

                <x-text-field
                    label="New Password"
                    id="password"
                    required
                    type="password"
                    wire:model="password"
                    autocomplete="new-password"
                    autofocus
                    :error="$errors->first('password')"
                />
                @unless ($errors->has('password'))
                    <p class="auth-hint">{{ $passwordHint }}</p>
                @endunless
                <x-text-field
                    label="Confirm New Password"
                    id="confirm-password"
                    required
                    type="password"
                    wire:model="confirmPassword"
                    autocomplete="new-password"
                    :error="$errors->first('confirmPassword')"
                />

                <div class="mt-1 flex flex-col gap-3">
                    <button type="submit" class="btn btn-primary w-full" wire:loading.attr="disabled" wire:target="resetPassword">
                        <span wire:loading.remove wire:target="resetPassword">Update Password</span>
                        <span wire:loading wire:target="resetPassword">Updating…</span>
                    </button>
                </div>
            </form>
        </x-auth-card>
    @endif
</div>
