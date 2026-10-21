@props(['company', 'title'])

{{-- A printable document (bill, receipt, statement): the company letterhead, the document, and a Print button. --}}

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
            <x-print-letterhead :company="$company" :title="$title">{{ $heading ?? '' }}</x-print-letterhead>

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
