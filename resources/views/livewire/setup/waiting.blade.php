<div class="mx-auto max-w-lg px-4 py-16">
    <div class="rounded-2xl border border-table-line bg-card px-6 py-8 text-center shadow-card">
        <x-app-logo :size="40" class="mx-auto" />
        <h1 class="mt-4 text-title font-bold text-ink-strong">Your administrator is finishing setup</h1>
        <p class="mt-2 text-body leading-6 text-ink-soft">
            {{ $company?->company_name ?? 'Your company' }} is still being set up. You'll be able to use the system as soon as your administrator finishes.
            Try signing in again later.
        </p>
    </div>
</div>
