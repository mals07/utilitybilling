<x-page title="Dashboard">
    <section aria-label="Company" class="relative overflow-hidden rounded-2xl bg-navy bg-[image:var(--gradient-ocean)] px-5 py-7 text-white shadow-[0_20px_40px_-20px_rgba(3,105,161,0.7)] sm:px-9 sm:py-9">
        {{-- Decorative waves --}}
        <svg class="pointer-events-none absolute inset-x-0 bottom-0 h-24 w-full text-white/10" viewBox="0 0 1200 120" preserveAspectRatio="none" aria-hidden="true">
            <path d="M0 60c150 40 300 40 450 0s300-40 450 0 225 30 300 15v45H0Z" fill="currentColor" />
            <path d="M0 85c200 30 350-10 550 5s350 35 650-5v35H0Z" fill="currentColor" />
        </svg>
        <div class="pointer-events-none absolute -top-16 -right-10 size-64 rounded-full bg-white/10 blur-2xl" aria-hidden="true"></div>

        <div class="relative flex flex-col gap-5 sm:flex-row sm:items-center">
            <div class="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-white shadow-lg ring-4 ring-white/25">
                @if ($logoUrl = $company->assetUrl('logo'))
                    <img src="{{ $logoUrl }}" alt="{{ $company->company_name }} logo" class="size-full object-contain p-2">
                @else
                    <x-app-logo :size="48" />
                @endif
            </div>

            <div class="min-w-0 flex-1">
                <p class="text-xs font-semibold tracking-[0.16em] text-cyan-100/80 uppercase">Water Utility</p>
                <h2 class="mt-1 truncate text-2xl font-bold tracking-tight">{{ $company->company_name }}</h2>
                <p class="mt-1.5 text-sm text-sky-100/90">{{ $company->location() }}</p>
                <p class="mt-1 text-sm text-sky-100/90">
                    Point person:
                    <span class="font-medium text-white">{{ $company->point_person_first_name }} {{ $company->point_person_last_name }}</span>
                    &middot; {{ $company->point_person_email }}
                </p>
            </div>

            @if ($canEditCompany)
                <a
                    href="{{ route('company') }}"
                    class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xs bg-white px-6 text-sm font-semibold text-ocean shadow-md transition hover:-translate-y-px hover:bg-sky-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                >
                    <x-icon name="pencil" :size="16" />
                    Edit company profile
                </a>
            @endif
        </div>
    </section>
</x-page>
