<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>{{ isset($title) ? $title.' | ' : '' }}{{ config('app.name') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @livewireStyles
    </head>
    <body class="min-h-screen bg-page font-sans text-ink">
        <main class="flex min-h-screen flex-col items-center px-4 pt-8 pb-10 sm:pt-16">
            <h1 class="mb-5 text-[22px] font-bold text-ink">Utility Billing</h1>
            {{ $slot }}
        </main>

        @livewireScripts
    </body>
</html>
