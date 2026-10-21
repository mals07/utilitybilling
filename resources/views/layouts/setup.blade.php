@php($company = auth()->user()->company)

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>{{ isset($title) ? $title.' | ' : '' }}{{ config('app.name') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @livewireStyles
    </head>
    <body class="min-h-screen bg-panel font-sans text-ink">
        <header class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-table-line bg-topbar px-4 lg:px-6">
            <div class="flex min-w-0 flex-1 items-center gap-2">
                <x-app-logo />
                <span class="truncate text-lg font-semibold">{{ $company?->company_name ?? config('app.name') }}</span>
                <span class="hidden text-sm text-label sm:inline">&middot; Company setup</span>
            </div>

            <x-restart-onboarding class="mr-4 hidden sm:flex" />

            <form method="POST" action="{{ route('logout') }}" x-data x-on:submit="if (! confirm('Sign out and finish setup later? Your progress is saved, and you will continue from this step when you sign in again.')) $event.preventDefault()">
                @csrf
                <button type="submit" class="text-button inline-flex items-center gap-2 text-sm">
                    <x-icon name="logout" :size="18" />
                    Finish later
                </button>
            </form>
        </header>

        <main>
            {{ $slot }}
        </main>

        <x-guide />

        @livewireScripts
    </body>
</html>
