<div class="flex w-full flex-col items-center">
    @if ($invitedUser === null)
        <x-auth-card title="Invitation expired">
            <x-alert tone="error">
                This invitation link is invalid or has expired. Invitation links can only be used once and expire after
                7 days. Ask your administrator to resend the invitation.
            </x-alert>
            <p class="text-center text-[13px] text-label">
                <a href="{{ route('login') }}" class="auth-link">Go to login</a>
            </p>
        </x-auth-card>
    @else
        <x-auth-card :title="'Welcome, '.$invitedUser->first_name">
            <x-slot:description>
                {{ $invitedUser->company?->company_name ?? 'Your company' }} invited you to Utility Billing. Create your
                username and password to finish setting up your account.
            </x-slot:description>

            <form wire:submit="accept" method="post" novalidate class="flex flex-col gap-[18px]">
                @if ($message)
                    <x-alert tone="error">{{ $message }}</x-alert>
                @endif

                <x-text-field label="Email Address" id="email" :value="$invitedUser->email" readonly autocomplete="email" />
                <x-text-field
                    label="Username"
                    id="username"
                    required
                    wire:model="username"
                    autocomplete="username"
                    autocapitalize="none"
                    spellcheck="false"
                    autofocus
                    :error="$errors->first('username')"
                />
                @unless ($errors->has('username'))
                    <p class="auth-hint">{{ $usernameHint }}</p>
                @endunless
                <x-text-field
                    label="Password"
                    id="password"
                    required
                    type="password"
                    wire:model="password"
                    autocomplete="new-password"
                    :error="$errors->first('password')"
                />
                @unless ($errors->has('password'))
                    <p class="auth-hint">{{ $passwordHint }}</p>
                @endunless
                <x-text-field
                    label="Confirm Password"
                    id="confirm-password"
                    required
                    type="password"
                    wire:model="confirmPassword"
                    autocomplete="new-password"
                    :error="$errors->first('confirmPassword')"
                />

                <div class="mt-1 flex flex-col gap-3">
                    <button type="submit" class="btn btn-primary w-full" wire:loading.attr="disabled" wire:target="accept">
                        <span wire:loading.remove wire:target="accept">Create Account</span>
                        <span wire:loading wire:target="accept">Creating account…</span>
                    </button>
                </div>
            </form>
        </x-auth-card>
    @endif
</div>
