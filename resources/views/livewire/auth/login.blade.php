<div class="flex w-full flex-col items-center">
    @if ($notice)
        <x-alert :tone="$notice['tone']" class="mb-3 w-full max-w-[460px]">{{ $notice['text'] }}</x-alert>
    @endif

    <x-auth-card active-tab="login">
        <form wire:submit="authenticate" method="post" novalidate class="flex flex-col gap-[18px]">
            @if ($message)
                <x-alert tone="error">{{ $message }}</x-alert>
            @endif

            <x-text-field
                label="Email Address or Username"
                id="login"
                wire:model="login"
                autocomplete="username"
                autocapitalize="none"
                spellcheck="false"
                autofocus
                :error="$errors->first('login')"
            />
            <x-text-field
                label="Password"
                id="password"
                type="password"
                wire:model="password"
                autocomplete="current-password"
                :error="$errors->first('password')"
            />

            <div class="flex flex-wrap items-center justify-between gap-2">
                <label class="inline-flex items-center gap-2 text-[13px] text-label">
                    <input type="checkbox" wire:model="remember" class="size-4 accent-accent">
                    Remember me
                </label>
                <a href="{{ route('password.request') }}" class="auth-link">Forgot password?</a>
            </div>

            <div class="mt-1 flex flex-col gap-3">
                <button type="submit" class="btn btn-primary w-full" wire:loading.attr="disabled" wire:target="authenticate">
                    <span wire:loading.remove wire:target="authenticate">Login</span>
                    <span wire:loading wire:target="authenticate">Logging in…</span>
                </button>
                <p class="text-center text-[13px] text-label">
                    Don't have an account?
                    <a href="{{ route('signup') }}" class="auth-link">Sign up</a>
                </p>
            </div>
        </form>
    </x-auth-card>
</div>
