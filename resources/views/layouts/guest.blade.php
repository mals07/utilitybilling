<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>{{ isset($title) ? $title.' | ' : '' }}{{ config('app.name') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @livewireStyles
    </head>
    <body class="water-backdrop min-h-screen font-sans text-ink">
        {{-- Decorative waves along the bottom of the page --}}
        <svg class="pointer-events-none fixed inset-x-0 bottom-0 -z-0 h-48 w-full text-ocean-bright/10" viewBox="0 0 1200 200" preserveAspectRatio="none" aria-hidden="true">
            <path d="M0 110c150 50 300 50 450 0s300-50 450 0 225 40 300 20v70H0Z" fill="currentColor" />
            <path d="M0 150c200 40 350-15 550 5s350 45 650-5v50H0Z" fill="currentColor" />
        </svg>

        <main class="relative flex min-h-screen flex-col items-center px-4 pt-8 pb-10 sm:pt-14">
            <div class="mb-6 flex flex-col items-center gap-3 text-center">
                <x-app-logo :size="52" class="drop-shadow-[0_10px_18px_rgba(3,105,161,0.35)]" />
                <div>
                    <h1 class="text-2xl font-extrabold tracking-tight text-ink-strong">Utility Billing</h1>
                    <p class="mt-0.5 text-sm text-label">Water billing &amp; customer management</p>
                </div>
            </div>
            {{ $slot }}
        </main>

        @livewireScripts
    </body>
</html>
