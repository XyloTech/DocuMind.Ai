<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full" data-theme>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="color-scheme" content="light dark">
        <meta name="description" content="DocuMind AI — Build and publish a product-support assistant powered by your business knowledge.">

        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        <link rel="icon" type="image/png" sizes="500x500" href="{{ asset('favicon.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('logo.png') }}">

        <title>@yield('title', config('app.name'))</title>

        {{-- Google Fonts: Inter for the sleek Grok feel --}}
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

        <script>
            // Applied before first paint so a dark-mode reload never flashes white.
            (() => {
                const stored = localStorage.getItem('documind_theme');
                const dark = stored === 'dark'
                    || (!stored && window.matchMedia('(prefers-color-scheme: dark)').matches);

                document.documentElement.classList.toggle('dark', dark);

                // `?motion=1` opts a single visit out of the OS-level reduced
                // motion setting, which otherwise hides the composer's glow
                // entirely and makes it impossible to review. See the matching
                // block at the end of resources/css/app.css.
                if (new URLSearchParams(window.location.search).get('motion') === '1') {
                    document.documentElement.dataset.motion = 'force';
                }
            })();
        </script>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body
        data-app-name="{{ config('app.name') }}"
        @if (request()->routeIs('chats.*')) data-fullscreen @endif
        class="h-full bg-slate-50 text-slate-900 antialiased selection:bg-indigo-500 selection:text-white dark:bg-black dark:text-white"
    >
        {{-- The chat workspace owns its own scrolling, so the page shell must
             not grow: the document itself never scrolls there. --}}
        <div @class([
            'flex flex-col',
            'h-full overflow-hidden' => request()->routeIs('chats.*'),
            'min-h-full' => ! request()->routeIs('chats.*'),
        ])>

            <header class="sticky top-0 z-30 border-b border-slate-200/80 bg-white/95 backdrop-blur-md dark:border-white/10 dark:bg-black/90">
                <div class="mx-auto flex h-14 max-w-7xl items-center justify-between gap-4 px-4 sm:px-6">
                    <div class="flex items-center gap-6">
                        {{-- Mobile Hamburger --}}
                        <button
                            type="button"
                            data-mobile-nav-toggle
                            aria-label="Toggle menu"
                            class="btn btn-ghost btn-icon btn-sm sm:hidden"
                        >
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="3" x2="21" y1="6" y2="6"/>
                                <line x1="3" x2="21" y1="12" y2="12"/>
                                <line x1="3" x2="21" y1="18" y2="18"/>
                            </svg>
                        </button>

                        <a href="{{ route('dashboard') }}" class="group flex items-center gap-2.5 text-sm font-semibold tracking-tight text-slate-900 dark:text-white">
                            <x-brand-mark :size="32" class="transition-transform group-hover:scale-105" />
                            <span class="flex items-center gap-1.5 font-bold">
                                <span>{{ config('app.name') }}</span>
                                <span class="rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-600 ring-1 ring-inset ring-indigo-500/20 dark:bg-indigo-500/10 dark:text-indigo-400 dark:ring-indigo-400/30">AI</span>
                            </span>
                        </a>

                        <nav class="hidden items-center gap-1 sm:flex">
                            <a
                                href="{{ route('dashboard') }}"
                                class="order-3 rounded-lg px-3 py-1.5 text-sm font-medium transition {{ request()->routeIs('dashboard') ? 'bg-slate-100 text-slate-900 dark:bg-white/10 dark:text-white' : 'text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-500 dark:hover:bg-white/5 dark:hover:text-white' }}"
                            >
                                Knowledge
                            </a>

                            <a
                                href="{{ route('chats.index') }}"
                                class="order-2 rounded-lg px-3 py-1.5 text-sm font-medium transition {{ request()->routeIs('chats.*') ? 'bg-slate-100 text-slate-900 dark:bg-white/10 dark:text-white' : 'text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-500 dark:hover:bg-white/5 dark:hover:text-white' }}"
                            >
                                Support
                            </a>

                            <a
                                href="{{ route('widget.index') }}"
                                class="order-1 rounded-lg px-3 py-1.5 text-sm font-medium transition {{ request()->routeIs('widget.*') ? 'bg-slate-100 text-slate-900 dark:bg-white/10 dark:text-white' : 'text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-500 dark:hover:bg-white/5 dark:hover:text-white' }}"
                            >
                                Bot builder
                            </a>

                            @can('access-admin')
                                <a
                                    href="{{ route('admin.dashboard') }}"
                                    class="order-4 rounded-lg px-3 py-1.5 text-sm font-medium transition {{ request()->routeIs('admin.*') ? 'bg-slate-100 text-slate-900 dark:bg-white/10 dark:text-white' : 'text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-500 dark:hover:bg-white/5 dark:hover:text-white' }}"
                                >
                                    Admin
                                </a>
                            @endcan

                            <a
                                href="{{ route('settings.privacy') }}"
                                class="order-5 rounded-lg px-3 py-1.5 text-sm font-medium transition {{ request()->routeIs('settings.*') ? 'bg-slate-100 text-slate-900 dark:bg-white/10 dark:text-white' : 'text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-500 dark:hover:bg-white/5 dark:hover:text-white' }}"
                            >
                                Privacy
                            </a>
                        </nav>
                    </div>

                    <div class="flex items-center gap-2.5">
                        <button
                            type="button"
                            data-theme-toggle
                            aria-label="Toggle dark mode"
                            title="Switch between light and dark mode"
                            class="btn btn-secondary btn-icon btn-sm"
                        >
                            <svg class="h-4 w-4 dark:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" />
                            </svg>
                            <svg class="hidden h-4 w-4 dark:block text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                            </svg>
                        </button>

                        @include('partials.notifications')

                        @include('partials.credit-pill')

                        <div class="relative" data-user-dropdown>
                            <button
                                type="button"
                                data-user-dropdown-toggle
                                aria-label="Open account menu for {{ auth()->user()->name }}"
                                class="flex cursor-pointer items-center gap-2 rounded-lg border border-transparent px-2 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-100 dark:text-slate-700 dark:hover:bg-white/5"
                            >
                                <x-blobatar :name="auth()->user()->email" :fallback="auth()->user()->name" :size="28" class="shadow-sm ring-2 ring-indigo-500/20" />
                                <span class="hidden font-medium sm:inline">{{ auth()->user()->name }}</span>
                                <svg class="h-3.5 w-3.5 text-slate-400 dark:text-slate-500 transition-transform" data-user-dropdown-chevron viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                                </svg>
                            </button>

                            <div
                                data-user-dropdown-panel
                                class="absolute right-0 mt-2 w-56 overflow-hidden rounded-xl border border-slate-200 bg-white dark:bg-[#0d0f15] py-1 shadow-xl transition-all duration-150 dark:border-white/10"
                                style="display: none;"
                            >
                                <div class="border-b border-slate-100 px-4 py-2.5 dark:border-white/10">
                                    <p class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ auth()->user()->name }}</p>
                                    <p class="truncate text-xs text-slate-500 dark:text-slate-500">{{ auth()->user()->email }}</p>
                                </div>

                                <div class="px-3 py-2 text-xs text-slate-500">
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                                        <span data-credits-count>{{ auth()->user()->credits }} {{ \Illuminate\Support\Str::plural('credit', auth()->user()->credits) }}</span> available
                                    </span>
                                </div>

                                <a
                                    href="{{ route('settings.privacy') }}"
                                    class="block px-4 py-2 text-left text-sm text-slate-600 transition hover:bg-slate-50 dark:text-slate-600 dark:hover:bg-white/5"
                                >
                                    Privacy &amp; data
                                </a>

                                <form method="POST" action="{{ route('logout') }}" data-firebase-logout data-firebase-config="{{ json_encode(config('services.firebase.web'), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG) }}" class="border-t border-slate-100 dark:border-white/10">
                                    @csrf
                                    <button
                                        type="submit"
                                        class="block w-full px-4 py-2 text-left text-sm text-rose-600 transition hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-500/10"
                                    >
                                        Sign out
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            {{-- Mobile Navigation Drawer --}}
            <div class="dm-mobile-nav" data-mobile-nav>
                <div class="dm-mobile-nav-backdrop" data-mobile-nav-backdrop></div>
                <div class="dm-mobile-nav-panel flex flex-col">
                    <div class="flex items-center justify-between border-b border-slate-200/80 p-4 dark:border-white/10">
                        <span class="text-sm font-bold text-slate-900 dark:text-white">Menu</span>
                        <button type="button" data-mobile-nav-close class="btn btn-ghost btn-icon btn-sm">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M18 6 6 18M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <nav class="flex flex-1 flex-col gap-1 p-3">
                        <a
                            href="{{ route('dashboard') }}"
                            class="order-3 flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('dashboard') ? 'bg-slate-100 text-slate-900 dark:bg-white/10 dark:text-white' : 'text-slate-600 hover:bg-slate-50 dark:text-slate-500 dark:hover:bg-white/5' }}"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/>
                                <polyline points="14 2 14 8 20 8"/>
                            </svg>
                            Knowledge
                        </a>
                        <a
                            href="{{ route('chats.index') }}"
                            class="order-2 flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('chats.*') ? 'bg-slate-100 text-slate-900 dark:bg-white/10 dark:text-white' : 'text-slate-600 hover:bg-slate-50 dark:text-slate-500 dark:hover:bg-white/5' }}"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                            </svg>
                            Support
                        </a>
                        <a
                            href="{{ route('widget.index') }}"
                            class="order-1 flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('widget.*') ? 'bg-slate-100 text-slate-900 dark:bg-white/10 dark:text-white' : 'text-slate-600 hover:bg-slate-50 dark:text-slate-500 dark:hover:bg-white/5' }}"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect width="18" height="18" x="3" y="3" rx="2"/>
                                <path d="M3 9h18"/>
                            </svg>
                            Bot builder
                        </a>
                        @can('access-admin')
                            <a
                                href="{{ route('admin.dashboard') }}"
                                class="order-4 flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('admin.*') ? 'bg-slate-100 text-slate-900 dark:bg-white/10 dark:text-white' : 'text-slate-600 hover:bg-slate-50 dark:text-slate-500 dark:hover:bg-white/5' }}"
                            >
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                                Admin
                            </a>
                        @endcan
                    </nav>

                    <div class="border-t border-slate-200/80 p-3 dark:border-white/10">
                        <div class="flex items-center gap-2.5 rounded-xl bg-slate-50 p-3 dark:bg-white/5">
                            <x-blobatar :name="auth()->user()->email" :fallback="auth()->user()->name" :size="32" class="ring-1 ring-indigo-500/20" />
                            <div class="min-w-0">
                                <p class="truncate text-xs font-semibold text-slate-900 dark:text-white">{{ auth()->user()->name }}</p>
                                <p class="truncate text-[11px] text-slate-500"><span data-credits-count>{{ auth()->user()->credits }} {{ \Illuminate\Support\Str::plural('credit', auth()->user()->credits) }}</span></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            @include('partials.flash')

            <main @class([
                'mx-auto w-full flex-1',
                'max-w-none min-h-0 overflow-hidden px-0 py-0' => request()->routeIs('chats.*'),
                'max-w-7xl px-4 py-8 sm:px-6' => ! request()->routeIs('chats.*'),
            ])>
                @yield('content')
            </main>
        </div>

        @include('partials.consent-modal')
    </body>
</html>