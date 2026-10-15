<div class="flex w-full flex-col items-center">
    <x-auth-card active-tab="signup">
        <form wire:submit="register" method="post" novalidate class="flex flex-col gap-[18px]">
            <x-text-field label="Full Name" id="name" wire:model="name" autocomplete="name" autofocus :error="$errors->first('name')" />
            <x-text-field label="Email Address" id="email" type="email" wire:model="email" autocomplete="email" :error="$errors->first('email')" />
            <x-text-field
                label="Password"
                id="password"
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
                type="password"
                wire:model="confirmPassword"
                autocomplete="new-password"
                :error="$errors->first('confirmPassword')"
            />

            <div class="mt-1 flex flex-col gap-3">
                <button type="submit" class="btn btn-primary w-full" wire:loading.attr="disabled" wire:target="register">
                    <span wire:loading.remove wire:target="register">Create Account</span>
                    <span wire:loading wire:target="register">Creating account…</span>
                </button>
                <p class="text-center text-[13px] text-label">
                    Already have an account?
                    <a href="{{ route('login') }}" class="auth-link">Log in</a>
                </p>
            </div>
        </form>
    </x-auth-card>
</div>
