@use('App\Enums\Page')

@php
    $user = auth()->user();
    $company = $user->company;

    // Users without a company may always open Company Setup, since that is where they create it.
    $visible = fn (Page $page): bool => $user->canAccessPage($page) || ($page === Page::CompanySetup && ! $company);
    $mainLinks = array_filter(Page::inGroup('main'), $visible);
    $settingsLinks = array_filter(Page::inGroup('settings'), $visible);
    $settingsActive = collect($settingsLinks)->contains(fn (Page $page): bool => request()->routeIs($page->value));
    $collapsed = request()->cookie('ub_sidebar') === 'collapsed';
    $logoUrl = $company?->assetUrl('logo');
@endphp

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
    <body class="min-h-screen bg-page font-sans text-ink">
        <div
            x-data="{
                collapsed: @js($collapsed),
                mobileOpen: false,
                settingsOpen: @js($settingsActive),
                isDesktop: () => window.matchMedia('(min-width: 1024px)').matches,
                remember(value) {
                    this.collapsed = value;
                    document.cookie = `ub_sidebar=${value ? 'collapsed' : 'expanded'}; path=/; max-age=31536000; samesite=lax`;
                },
                toggleSidebar() {
                    if (this.isDesktop()) this.remember(! this.collapsed);
                    else this.mobileOpen = ! this.mobileOpen;
                },
                toggleSettings() {
                    // While collapsed the links are hidden, so expand the sidebar to show them.
                    if (this.collapsed && this.isDesktop()) {
                        this.remember(false);
                        this.settingsOpen = true;
                        return;
                    }
                    this.settingsOpen = ! this.settingsOpen;
                },
            }"
            x-bind:data-collapsed="collapsed"
            data-collapsed="{{ $collapsed ? 'true' : 'false' }}"
            x-on:keydown.escape.window="mobileOpen = false"
            class="min-h-screen"
        >
            <header class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-table-line bg-topbar px-4 shadow-[0_1px_0_rgba(11,37,69,0.02),0_6px_20px_-12px_rgba(11,37,69,0.18)] backdrop-blur-md lg:h-20 lg:px-6">
                <button
                    type="button"
                    x-on:click="toggleSidebar()"
                    class="inline-flex size-10 shrink-0 items-center justify-center rounded-xs border border-table-line bg-card text-accent shadow-[0_1px_2px_rgba(11,37,69,0.05)] transition-colors hover:border-line hover:bg-panel focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-line-focus"
                    aria-controls="sidebar"
                    aria-label="Toggle navigation"
                >
                    <x-icon name="menu" />
                </button>

                {{-- On desktop the sidebar shows the company brand instead. --}}
                <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-2.5 text-ink-strong lg:hidden">
                    @if ($logoUrl)
                        <img src="{{ $logoUrl }}" alt="{{ $company->company_name }}" class="h-9 max-w-48 object-contain lg:h-11">
                    @else
                        <x-app-logo :size="36" />
                        <span class="truncate text-heading font-bold tracking-tight">{{ $company?->company_name ?? config('app.name') }}</span>
                    @endif
                </a>

                <div class="ml-auto hidden items-center gap-3 sm:flex">
                    <div class="text-right leading-tight">
                        <p class="max-w-48 truncate text-body font-semibold text-ink-strong">{{ $user->name }}</p>
                        @if ($company)
                            <p class="max-w-48 truncate text-caption text-label">{{ $company->company_name }}</p>
                        @endif
                    </div>
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-ocean to-aqua text-body font-bold text-white uppercase shadow-[0_4px_12px_-4px_rgba(3,105,161,0.6)] ring-2 ring-white" aria-hidden="true">
                        {{ mb_substr($user->name, 0, 1) }}
                    </span>
                </div>
            </header>

            <div class="flex min-h-[calc(100vh-4rem)] lg:min-h-[calc(100vh-5rem)]">
                <div x-show="mobileOpen" x-on:click="mobileOpen = false" x-cloak class="fixed inset-0 z-40 bg-overlay lg:hidden" aria-hidden="true"></div>

                <aside
                    id="sidebar"
                    aria-label="Sidebar"
                    x-bind:data-open="mobileOpen"
                    class="fixed inset-y-0 left-0 z-50 w-64 -translate-x-full bg-sidebar bg-[image:var(--gradient-navy)] text-white shadow-[4px_0_24px_-12px_rgba(11,37,69,0.6)] transition-transform duration-200 data-[open=true]:translate-x-0 lg:static lg:z-auto lg:w-52 lg:shrink-0 lg:translate-x-0 lg:transition-[width] lg:sidebar-collapsed:w-[72px]"
                >
                    <div class="flex h-full flex-col lg:sticky lg:top-20 lg:h-[calc(100vh-5rem)]">
                        {{-- Company brand; only the logo shows while collapsed. --}}
                        <div class="flex items-center gap-2 border-b border-white/10 px-4 py-4 lg:px-4 lg:py-5 lg:sidebar-collapsed:justify-center lg:sidebar-collapsed:px-0">
                            <a
                                href="{{ route('dashboard') }}"
                                class="flex min-w-0 flex-1 items-center gap-3 rounded-xs focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-aqua lg:sidebar-collapsed:flex-none"
                                x-bind:title="collapsed ? @js($company?->company_name ?? config('app.name')) : null"
                            >
                                @if ($logoUrl)
                                    <span class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-white p-1 shadow-[0_6px_16px_-6px_rgba(0,0,0,0.5)] ring-1 ring-white/20">
                                        <img src="{{ $logoUrl }}" alt="" class="size-full object-contain">
                                    </span>
                                @else
                                    <x-app-logo :size="44" class="drop-shadow-[0_6px_12px_rgba(6,182,212,0.35)]" />
                                @endif
                                <span class="min-w-0 lg:sidebar-collapsed:sr-only">
                                    <span class="line-clamp-2 text-body leading-tight font-bold text-white">{{ $company?->company_name ?? config('app.name') }}</span>
                                    <span class="mt-0.5 block text-caption text-cyan-200/70">Water Billing</span>
                                </span>
                            </a>
                            <button
                                type="button"
                                x-on:click="mobileOpen = false"
                                class="inline-flex size-9 shrink-0 items-center justify-center rounded-xs text-white/80 hover:bg-white/10 hover:text-white lg:hidden"
                                aria-label="Close navigation"
                            >
                                <x-icon name="close" />
                            </button>
                        </div>

                        <nav aria-label="Main" class="flex flex-1 flex-col gap-1 overflow-y-auto px-3 py-4 lg:py-3">
                            <a
                                href="{{ route('dashboard') }}"
                                class="nav-item"
                                data-tour="dashboard"
                                @if (request()->routeIs('dashboard')) aria-current="page" @endif
                                x-bind:title="collapsed ? 'Dashboard' : null"
                            >
                                <x-icon name="home" />
                                <span class="flex-1 truncate lg:sidebar-collapsed:sr-only">Dashboard</span>
                            </a>

                            @foreach ($mainLinks as $page)
                                <a
                                    href="{{ route($page->value) }}"
                                    class="nav-item"
                                    data-tour="{{ $page->value }}"
                                    @if (request()->routeIs($page->value)) aria-current="page" @endif
                                    x-bind:title="collapsed ? @js($page->label()) : null"
                                >
                                    <x-icon :name="match ($page) { Page::Customers => 'user', Page::WorkOrders => 'stack', Page::MeterReadings => 'gauge', Page::Invoices => 'receipt', Page::Payments => 'cash', Page::Disconnections => 'warning', default => 'home' }" />
                                    <span class="flex-1 truncate lg:sidebar-collapsed:sr-only">{{ $page->label() }}</span>
                                </a>
                            @endforeach

                            @if ($settingsLinks !== [])
                                <div class="mt-3 border-t border-white/10 pt-3">
                                    <button
                                        type="button"
                                        class="nav-item"
                                        data-tour="settings"
                                        data-active="{{ $settingsActive ? 'true' : 'false' }}"
                                        x-bind:aria-expanded="settingsOpen.toString()"
                                        aria-controls="sidebar-group-settings"
                                        x-bind:title="collapsed ? 'Settings' : null"
                                        x-on:click="toggleSettings()"
                                    >
                                        <x-icon name="gear" />
                                        <span class="flex-1 truncate lg:sidebar-collapsed:sr-only">Settings</span>
                                        <x-icon name="chevron-down" :size="16" class="transition-transform duration-150 lg:sidebar-collapsed:hidden" x-bind:class="settingsOpen && 'rotate-180'" />
                                    </button>

                                    <div id="sidebar-group-settings" x-show="settingsOpen" @unless ($settingsActive) x-cloak @endunless class="mt-1 flex flex-col gap-0.5 lg:sidebar-collapsed:hidden">
                                        @foreach ($settingsLinks as $page)
                                            <a
                                                href="{{ route($page->value) }}"
                                                class="nav-item"
                                                @if (request()->routeIs($page->value)) aria-current="page" @endif
                                            >
                                                <span class="truncate">{{ $page->label() }}</span>
                                            </a>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </nav>

                        <div class="border-t border-white/10 px-3 py-4 lg:sidebar-collapsed:px-2">
                            <div class="flex items-center gap-3 rounded-[10px] bg-white/5 px-2.5 py-2 lg:sidebar-collapsed:justify-center lg:sidebar-collapsed:bg-transparent lg:sidebar-collapsed:px-0">
                                <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-ocean-bright to-aqua text-caption font-bold text-white uppercase" aria-hidden="true">{{ mb_substr($user->name, 0, 1) }}</span>
                                <p class="min-w-0 truncate text-caption font-medium text-white/85 lg:sidebar-collapsed:sr-only">{{ $user->name }}</p>
                            </div>

                            <x-restart-onboarding class="mt-2 h-9 w-full rounded-xs px-2.5 text-amber-300! hover:bg-white/5 lg:sidebar-collapsed:justify-center lg:sidebar-collapsed:px-0" />

                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button
                                    type="submit"
                                    x-bind:title="collapsed ? 'Log out' : null"
                                    class="mt-1 flex h-9 w-full items-center gap-3 rounded-xs px-2.5 text-body font-medium text-white/70 transition-colors hover:bg-white/10 hover:text-white lg:sidebar-collapsed:justify-center lg:sidebar-collapsed:px-0"
                                >
                                    <x-icon name="logout" />
                                    <span class="lg:sidebar-collapsed:sr-only">Log out</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </aside>

                <main class="min-w-0 flex-1 overflow-x-clip">
                    {{ $slot }}
                </main>
            </div>
        </div>

        @if ($company)
            <livewire:onboarding.tour />
        @endif

        <x-guide />

        @livewireScripts
    </body>
</html>
