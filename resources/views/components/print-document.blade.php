@props(['company', 'title'])

{{-- A printable document (bill, receipt, statement): the company letterhead, the document, and a Print button. --}}
@php($logoUrl = $company->assetUrl('logo'))

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>{{ $title }} | {{ $company->company_name }}</title>
        @vite(['resources/css/app.css'])
        <style>
            @page { size: A4; margin: 14mm; }
            @media print {
                .no-print { display: none !important; }
                body { background: #fff; }
                .print-sheet { box-shadow: none; border: 0; padding: 0; max-width: none; }
            }
        </style>
    </head>
    <body class="bg-panel font-sans text-ink">
        <div class="no-print mx-auto flex max-w-[800px] items-center justify-between gap-3 px-4 py-3">
            <button type="button" onclick="history.length > 1 ? history.back() : window.close()" class="link-button">← Back</button>
            <button type="button" onclick="window.print()" class="form-button form-button-sm min-w-32">Print / Save as PDF</button>
        </div>

        <main class="print-sheet mx-auto mb-8 max-w-[800px] border border-table-line bg-card px-8 py-8 text-[13px]">
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
                    {{ $heading ?? '' }}
                </div>
            </header>

            {{ $slot }}

            <footer class="mt-8 border-t border-line pt-3 text-center text-xs text-label">
                @foreach ([$company->billing_footer_1, $company->billing_footer_2, $company->billing_footer_3] as $line)
                    @if ($line)
                        <p>{{ $line }}</p>
                    @endif
                @endforeach
                <p class="mt-1">Printed {{ now()->setTimezone($company->timezone ?? 'Asia/Manila')->format('m/d/Y g:i A') }}</p>
            </footer>
        </main>
    </body>
</html>
