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
            <header class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-table-line bg-topbar px-4 lg:h-20 lg:px-6">
                <button
                    type="button"
                    x-on:click="toggleSidebar()"
                    class="inline-flex size-9 shrink-0 items-center justify-center rounded-xs text-accent hover:bg-hover focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-line-focus"
                    aria-controls="sidebar"
                    aria-label="Toggle navigation"
                >
                    <x-icon name="menu" />
                </button>

                <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-2 text-ink">
                    @if ($logoUrl)
                        <img src="{{ $logoUrl }}" alt="{{ $company->company_name }}" class="h-9 max-w-48 object-contain lg:h-11">
                    @else
                        <x-app-logo />
                        <span class="truncate text-lg font-semibold">{{ $company?->company_name ?? config('app.name') }}</span>
                    @endif
                </a>
            </header>

            <div class="flex min-h-[calc(100vh-4rem)] lg:min-h-[calc(100vh-5rem)]">
                <div x-show="mobileOpen" x-on:click="mobileOpen = false" x-cloak class="fixed inset-0 z-40 bg-overlay lg:hidden" aria-hidden="true"></div>

                <aside
                    id="sidebar"
                    aria-label="Sidebar"
                    x-bind:data-open="mobileOpen"
                    class="fixed inset-y-0 left-0 z-50 w-64 -translate-x-full bg-sidebar transition-transform duration-200 data-[open=true]:translate-x-0 lg:static lg:z-auto lg:w-44 lg:shrink-0 lg:translate-x-0 lg:transition-[width] lg:sidebar-collapsed:w-16"
                >
                    <div class="flex h-full flex-col lg:sticky lg:top-20 lg:h-[calc(100vh-5rem)]">
                        <div class="flex h-16 items-center justify-end px-3 lg:hidden">
                            <button
                                type="button"
                                x-on:click="mobileOpen = false"
                                class="inline-flex size-9 items-center justify-center rounded-xs text-accent hover:bg-hover"
                                aria-label="Close navigation"
                            >
                                <x-icon name="close" />
                            </button>
                        </div>

                        <nav aria-label="Main" class="flex-1 overflow-y-auto py-4 lg:py-6">
                            <a
                                href="{{ route('dashboard') }}"
                                class="nav-item"
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
                                    @if (request()->routeIs($page->value)) aria-current="page" @endif
                                    x-bind:title="collapsed ? @js($page->label()) : null"
                                >
                                    <x-icon :name="match ($page) { Page::Customers => 'user', Page::WorkOrders => 'stack', Page::Invoices => 'receipt', Page::Payments => 'cash', default => 'home' }" />
                                    <span class="flex-1 truncate lg:sidebar-collapsed:sr-only">{{ $page->label() }}</span>
                                </a>
                            @endforeach

                            @if ($settingsLinks !== [])
                                <div class="mt-1">
                                    <button
                                        type="button"
                                        class="nav-item"
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

                                    <div id="sidebar-group-settings" x-show="settingsOpen" @unless ($settingsActive) x-cloak @endunless class="lg:sidebar-collapsed:hidden">
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

                        <div class="border-t border-line px-3 py-4 lg:sidebar-collapsed:px-2">
                            <p class="truncate px-2 text-xs font-medium text-label lg:sidebar-collapsed:sr-only">{{ $user->name }}</p>

                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button
                                    type="submit"
                                    x-bind:title="collapsed ? 'Log out' : null"
                                    class="mt-2 flex h-9 w-full items-center gap-3 rounded-xs px-2 text-sm text-ink-soft hover:bg-hover hover:text-ink-strong lg:sidebar-collapsed:justify-center lg:sidebar-collapsed:px-0"
                                >
                                    <x-icon name="logout" />
                                    <span class="lg:sidebar-collapsed:sr-only">Log out</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </aside>

                <main class="min-w-0 flex-1">
                    {{ $slot }}
                </main>
            </div>
        </div>

        @livewireScripts
    </body>
</html>
