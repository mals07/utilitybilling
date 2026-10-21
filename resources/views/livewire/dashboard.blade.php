<x-page title="Dashboard">
    <section aria-label="Company" class="flex flex-col gap-5 border border-table-line bg-card px-4 py-6 sm:flex-row sm:items-center sm:px-8">
        <div class="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-xs border border-line bg-card">
            @if ($logoUrl = $company->assetUrl('logo'))
                <img src="{{ $logoUrl }}" alt="{{ $company->company_name }} logo" class="size-full object-contain p-1.5">
            @else
                <x-app-logo :size="36" />
            @endif
        </div>

        <div class="min-w-0 flex-1">
            <h2 class="truncate text-lg font-semibold text-ink">{{ $company->company_name }}</h2>
            <p class="mt-1 text-sm text-label">{{ $company->location() }}</p>
            <p class="mt-1 text-sm text-label">
                Point person:
                <span class="text-ink">{{ $company->point_person_first_name }} {{ $company->point_person_last_name }}</span>
                &middot; {{ $company->point_person_email }}
            </p>
        </div>

        @if ($canEditCompany)
            <a
                href="{{ route('company') }}"
                class="inline-flex h-11 shrink-0 items-center justify-center rounded-xs bg-button px-6 text-sm font-medium text-white hover:bg-button-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-line-focus"
            >
                Edit company profile
            </a>
        @endif
    </section>
</x-page>
