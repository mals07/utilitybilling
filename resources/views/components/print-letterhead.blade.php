@props(['company', 'title'])

{{-- The company letterhead at the top of a printable document, with the document's title and heading. --}}
@php($logoUrl = $company->assetUrl('logo'))

<header class="flex items-start justify-between gap-6 border-b border-line pb-4">
    <div class="flex items-start gap-3">
        @if ($logoUrl)
            <img src="{{ $logoUrl }}" alt="" class="h-14 max-w-40 object-contain">
        @endif
        <div>
            <p class="text-base font-bold text-ink-strong">{{ $company->company_name }}</p>
            @if ($company->company_header_line)
                <p>{{ $company->company_header_line }}</p>
            @endif
            <p class="text-label">{{ $company->location() }}</p>
            <p class="text-label">
                {{ collect([$company->phone_number, $company->company_email ?? $company->email, $company->tax_id_number ? 'TIN '.$company->tax_id_number : null])->filter()->implode(' · ') }}
            </p>
            @foreach ([$company->billing_header_1, $company->billing_header_2, $company->billing_header_3] as $line)
                @if ($line)
                    <p class="text-label">{{ $line }}</p>
                @endif
            @endforeach
        </div>
    </div>
    <div class="text-right">
        <p class="text-lg font-bold tracking-wide text-ink-strong uppercase">{{ $title }}</p>
        {{ $slot }}
    </div>
</header>
