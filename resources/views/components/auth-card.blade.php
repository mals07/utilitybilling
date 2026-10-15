@props([
    // Shows the Login / Sign Up tabs with this one active: "login" or "signup".
    'activeTab' => null,
    'title' => null,
    'description' => null,
])

<div @class([
    'w-full max-w-[460px] bg-card max-[480px]:p-0 max-[480px]:shadow-none',
    'px-3.5 pb-3.5 shadow-card',
    'pt-3.5 max-[480px]:pt-0' => $activeTab === null,
])>
    @if ($activeTab)
        <nav aria-label="Account" class="tab-scroller">
            <div class="tab-list">
                <a href="{{ route('login') }}" class="tab" @if ($activeTab === 'login') aria-current="page" @endif>Login</a>
                <a href="{{ route('signup') }}" class="tab" @if ($activeTab === 'signup') aria-current="page" @endif>Sign Up</a>
            </div>
        </nav>
    @endif

    <section class="flex flex-col gap-[18px] bg-panel px-5 pt-7 pb-6 sm:px-8 sm:pt-9 sm:pb-8" @if ($title) aria-labelledby="auth-title" @endif>
        @if ($title)
            <header class="flex flex-col gap-2">
                <h2 id="auth-title" class="text-base font-semibold text-ink">{{ $title }}</h2>
                @if ($description)
                    <p class="text-[13px] leading-relaxed text-label">{{ $description }}</p>
                @endif
            </header>
        @endif

        {{ $slot }}
    </section>
</div>
