@props([
    // Shows the Login / Sign Up tabs with this one active: "login" or "signup".
    'activeTab' => null,
    'title' => null,
    'description' => null,
])

<div @class([
    'w-full max-w-[460px] overflow-hidden rounded-2xl border border-white/70 bg-card/95 backdrop-blur-sm max-[480px]:rounded-xl',
    'p-2 shadow-[0_30px_60px_-30px_rgba(11,37,69,0.45),0_8px_24px_-12px_rgba(3,105,161,0.25)]',
])>
    @if ($activeTab)
        <nav aria-label="Account" class="tab-scroller px-2 pt-2">
            <div class="tab-list w-full [&>.tab]:min-w-0 [&>.tab]:flex-1">
                <a href="{{ route('login') }}" class="tab" @if ($activeTab === 'login') aria-current="page" @endif>Login</a>
                <a href="{{ route('signup') }}" class="tab" @if ($activeTab === 'signup') aria-current="page" @endif>Sign Up</a>
            </div>
        </nav>
    @endif

    <section class="flex flex-col gap-[18px] px-5 pt-6 pb-6 sm:px-8 sm:pt-7 sm:pb-8" @if ($title) aria-labelledby="auth-title" @endif>
        @if ($title)
            <header class="flex flex-col gap-2">
                <h2 id="auth-title" class="text-lg font-bold tracking-tight text-ink-strong">{{ $title }}</h2>
                @if ($description)
                    <p class="text-[13px] leading-relaxed text-label">{{ $description }}</p>
                @endif
            </header>
        @endif

        {{ $slot }}
    </section>
</div>
