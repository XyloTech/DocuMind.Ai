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
        {{-- Manrope keeps dense workspace text crisp without losing warmth. --}}
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">

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
        class="h-full bg-slate-50 text-slate-900 antialiased selection:bg-indigo-500 selection:text-white dark:bg-[#090b10] dark:text-white"
    >
        {{-- The chat workspace owns its own scrolling, so the page shell must
             not grow: the document itself never scrolls there. --}}
        <div @class([
            'flex flex-col',
            'h-full overflow-hidden' => request()->routeIs('chats.*'),
            'min-h-full' => ! request()->routeIs('chats.*'),
        ])>

            <header class="sticky top-0 z-30 border-b border-slate-200 bg-white dark:border-white/10 dark:bg-[#0d0f15]">
                <div class="mx-auto flex h-16 max-w-7xl items-center justify-between gap-1 px-1.5 sm:gap-4 sm:px-6">
                    <div class="flex min-w-0 items-center gap-1 sm:gap-2 lg:gap-4 xl:gap-6">
                        {{-- Mobile Hamburger --}}
                        <button
                            type="button"
                            data-mobile-nav-toggle
                            aria-controls="mobile-navigation"
                            aria-expanded="false"
                            aria-label="Toggle menu"
                            class="btn btn-ghost btn-icon h-11 w-11 rounded-2xl xl:hidden"
                        >
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25">
                                <line x1="3" x2="21" y1="6" y2="6"/>
                                <line x1="3" x2="21" y1="12" y2="12"/>
                                <line x1="3" x2="21" y1="18" y2="18"/>
                            </svg>
                        </button>

                        <a href="{{ route('dashboard') }}" class="group flex items-center gap-2.5 text-sm font-semibold tracking-tight text-slate-900 dark:text-white">
                            <x-brand-mark :size="32" class="transition-transform duration-200 group-hover:scale-105" />
                            <span class="hidden items-center gap-1.5 font-bold sm:flex">
                                <span>{{ config('app.name') }}</span>
                                <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-600 dark:bg-white/[0.08] dark:text-slate-300">AI</span>
                            </span>
                        </a>

                        <nav class="hidden items-center gap-1 rounded-lg border border-slate-200 bg-slate-50 p-1 dark:border-white/10 dark:bg-white/[0.03] xl:flex">
                            <a
                                href="{{ route('dashboard') }}"
                                class="order-3 rounded-full px-3.5 py-1.5 text-sm font-medium transition-all {{ request()->routeIs('dashboard') ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200 dark:bg-white/10 dark:text-white dark:ring-white/10' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-white/6 dark:hover:text-white' }}"
                            >
                                Knowledge
                            </a>

                            <a
                                href="{{ route('chats.index') }}"
                                class="order-2 rounded-full px-3.5 py-1.5 text-sm font-medium transition-all {{ request()->routeIs('chats.*') ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200 dark:bg-white/10 dark:text-white dark:ring-white/10' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-white/6 dark:hover:text-white' }}"
                            >
                                Chat
                            </a>

                            <a
                                href="{{ route('widget.index') }}"
                                class="order-1 rounded-full px-3.5 py-1.5 text-sm font-medium transition-all {{ request()->routeIs('widget.*') ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200 dark:bg-white/10 dark:text-white dark:ring-white/10' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-white/6 dark:hover:text-white' }}"
                            >
                                Bot builder
                            </a>

                            @can('access-admin')
                                <a
                                    href="{{ route('admin.dashboard') }}"
                                    class="order-4 rounded-full px-3.5 py-1.5 text-sm font-medium transition-all {{ request()->routeIs('admin.*') ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200 dark:bg-white/10 dark:text-white dark:ring-white/10' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-white/6 dark:hover:text-white' }}"
                                >
                                    Admin
                                </a>
                            @endcan

                            <a
                                href="{{ route('billing.index') }}"
                                class="order-5 rounded-full px-3.5 py-1.5 text-sm font-medium transition-all {{ request()->routeIs('billing.*') ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200 dark:bg-white/10 dark:text-white dark:ring-white/10' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-white/6 dark:hover:text-white' }}"
                            >
                                Billing
                            </a>

                            <a
                                href="{{ route('settings.privacy') }}"
                                class="order-6 rounded-full px-3.5 py-1.5 text-sm font-medium transition-all {{ request()->routeIs('settings.*') ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200 dark:bg-white/10 dark:text-white dark:ring-white/10' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-white/6 dark:hover:text-white' }}"
                            >
                                Privacy
                            </a>
                        </nav>
                    </div>

                    <div class="flex shrink-0 items-center gap-1 sm:gap-2.5">
                        <div class="hidden" aria-hidden="true">
                            @include('partials.notifications')
                        </div>

                        <div class="relative" data-user-dropdown>
                            <button
                                type="button"
                                data-user-dropdown-toggle
                                data-account-name="{{ auth()->user()->name }}"
                                aria-label="Open account menu for {{ auth()->user()->name }}"
                                class="flex h-11 w-11 cursor-pointer items-center justify-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-1 py-1 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:bg-white sm:h-auto sm:w-auto sm:justify-start sm:rounded-lg sm:px-2.5 sm:py-1.5 dark:border-white/10 dark:bg-white/[0.03] dark:text-slate-200 dark:hover:border-white/20 dark:hover:bg-white/[0.06]"
                            >
                                <span class="relative inline-flex shrink-0">
                                    <x-blobatar :name="auth()->user()->email" :fallback="auth()->user()->name" :size="28" class="shadow-sm ring-2 ring-indigo-500/20" />
                                    @php($unreadNotificationCount = auth()->user()->unreadNotificationCount())
                                    <span data-account-notifications-badge @if ($unreadNotificationCount === 0) hidden @endif aria-label="{{ $unreadNotificationCount }} unread notifications" class="absolute -right-1 -top-1 z-10 flex h-[18px] min-w-[18px] items-center justify-center rounded-full border-2 border-white bg-rose-600 px-1 text-[9px] font-bold leading-none text-white dark:border-[#0d0f15]">{{ $unreadNotificationCount > 9 ? '9+' : $unreadNotificationCount }}</span>
                                </span>
                                <span class="hidden font-medium sm:inline">{{ auth()->user()->name }}</span>
                                <svg class="hidden h-3.5 w-3.5 text-slate-400 transition-transform sm:block dark:text-slate-500" data-user-dropdown-chevron viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                                </svg>
                            </button>

                            <div
                                data-user-dropdown-panel
                                class="absolute right-0 mt-2 w-56 max-w-[calc(100vw-1rem)] overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-lg shadow-slate-900/10 transition-all duration-150 dark:border-white/10 dark:bg-[#0d0f15] dark:shadow-black/30"
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

                                <button
                                    type="button"
                                    data-theme-toggle
                                    aria-label="Switch color theme"
                                    aria-pressed="false"
                                    title="Switch color theme"
                                    class="flex min-h-10 w-full items-center gap-2.5 px-4 py-2 text-left text-sm text-slate-700 transition hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-white/[0.05]"
                                >
                                    <svg class="h-4 w-4 dark:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path d="M12 3v2m0 14v2m9-9h-2M5 12H3m15.36 6.36-1.42-1.42M7.05 7.05 5.64 5.64m12.72 0-1.42 1.41M7.05 16.95l-1.41 1.41"/>
                                        <circle cx="12" cy="12" r="4"/>
                                    </svg>
                                    <svg class="hidden h-4 w-4 text-amber-500 dark:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path d="M20.9 13A8.5 8.5 0 0 1 11 3.1 8.5 8.5 0 1 0 20.9 13Z"/>
                                    </svg>
                                    Switch theme
                                </button>

                                <a
                                    href="{{ route('notifications.index') }}"
                                    class="flex items-center justify-between gap-3 px-4 py-2 text-left text-sm text-slate-600 transition hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-white/[0.05]"
                                >
                                    Notifications
                                    <span data-account-notifications-badge @if ($unreadNotificationCount === 0) hidden @endif aria-label="{{ $unreadNotificationCount }} unread notifications" class="rounded-full bg-rose-600 px-2 py-0.5 text-[10px] font-semibold tabular-nums text-white">{{ $unreadNotificationCount > 9 ? '9+' : $unreadNotificationCount }}</span>
                                </a>

                                <a
                                    href="{{ route('settings.notifications') }}"
                                    class="block px-4 py-2 text-left text-sm text-slate-600 transition hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-white/[0.05]"
                                >
                                    Notification preferences
                                </a>

                                <a
                                    href="{{ route('billing.index') }}"
                                    class="block px-4 py-2 text-left text-sm text-slate-600 transition hover:bg-slate-50 dark:text-slate-600 dark:hover:bg-white/5"
                                >
                                    Billing &amp; credits
                                </a>

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
            <div id="mobile-navigation" class="dm-mobile-nav" data-mobile-nav aria-hidden="true">
                <div class="dm-mobile-nav-backdrop" data-mobile-nav-backdrop></div>
                <div class="dm-mobile-nav-panel flex flex-col" role="dialog" aria-modal="true" aria-label="Main navigation">
                    <div class="flex items-center justify-between border-b border-slate-200/80 px-4 py-4 dark:border-white/10">
                        <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-3">
                            <x-brand-mark :size="34" />
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-bold text-slate-900 dark:text-white">{{ config('app.name') }}</span>
                                <span class="block text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500 dark:text-slate-400">Workspace</span>
                            </span>
                        </a>
                        <button type="button" data-mobile-nav-close aria-label="Close menu" class="btn btn-ghost btn-icon btn-sm">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M18 6 6 18M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <nav class="flex flex-1 flex-col gap-1 p-3">
                        <a
                            href="{{ route('dashboard') }}"
                            aria-current="{{ request()->routeIs('dashboard') ? 'page' : 'false' }}"
                            class="order-3 flex min-h-11 items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('dashboard') ? 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200/70 dark:bg-indigo-500/10 dark:text-indigo-200 dark:ring-indigo-400/20' : 'text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-white/5' }}"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/>
                                <polyline points="14 2 14 8 20 8"/>
                            </svg>
                            Knowledge
                        </a>
                        <a
                            href="{{ route('chats.index') }}"
                            aria-current="{{ request()->routeIs('chats.*') ? 'page' : 'false' }}"
                            class="order-2 flex min-h-11 items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('chats.*') ? 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200/70 dark:bg-indigo-500/10 dark:text-indigo-200 dark:ring-indigo-400/20' : 'text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-white/5' }}"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                            </svg>
                            Chat
                        </a>
                        <a
                            href="{{ route('widget.index') }}"
                            aria-current="{{ request()->routeIs('widget.*') ? 'page' : 'false' }}"
                            class="order-1 flex min-h-11 items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('widget.*') ? 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200/70 dark:bg-indigo-500/10 dark:text-indigo-200 dark:ring-indigo-400/20' : 'text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-white/5' }}"
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
                                aria-current="{{ request()->routeIs('admin.*') ? 'page' : 'false' }}"
                                class="order-4 flex min-h-11 items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('admin.*') ? 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200/70 dark:bg-indigo-500/10 dark:text-indigo-200 dark:ring-indigo-400/20' : 'text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-white/5' }}"
                            >
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                                Admin
                            </a>
                        @endcan
                        <a
                            href="{{ route('billing.index') }}"
                            aria-current="{{ request()->routeIs('billing.*') ? 'page' : 'false' }}"
                            class="order-5 flex min-h-11 items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('billing.*') ? 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200/70 dark:bg-indigo-500/10 dark:text-indigo-200 dark:ring-indigo-400/20' : 'text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-white/5' }}"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect width="20" height="14" x="2" y="5" rx="2"/>
                                <line x1="2" x2="22" y1="10" y2="10"/>
                            </svg>
                            Billing
                        </a>
                        <a
                            href="{{ route('settings.privacy') }}"
                            aria-current="{{ request()->routeIs('settings.*') ? 'page' : 'false' }}"
                            class="order-6 flex min-h-11 items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('settings.*') ? 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200/70 dark:bg-indigo-500/10 dark:text-indigo-200 dark:ring-indigo-400/20' : 'text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-white/5' }}"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect width="18" height="11" x="3" y="11" rx="2" ry="2"/>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                            </svg>
                            Privacy
                        </a>
                    </nav>

                    <div class="border-t border-slate-200/80 p-3 dark:border-white/10">
                        <div class="flex items-center gap-2 rounded-xl border border-slate-200/70 bg-slate-50 p-2.5 dark:border-white/10 dark:bg-white/[0.03]">
                            <x-blobatar :name="auth()->user()->email" :fallback="auth()->user()->name" :size="32" class="ring-1 ring-indigo-500/20" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-xs font-semibold text-slate-900 dark:text-white">{{ auth()->user()->name }}</p>
                                <p class="truncate text-[11px] text-slate-500"><span data-credits-count>{{ auth()->user()->credits }} {{ \Illuminate\Support\Str::plural('credit', auth()->user()->credits) }}</span> available</p>
                            </div>
                            <form method="POST" action="{{ route('logout') }}" data-firebase-logout data-firebase-config="{{ json_encode(config('services.firebase.web'), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG) }}">
                                @csrf
                                <button type="submit" class="btn btn-ghost btn-icon h-10 w-10 rounded-lg text-slate-500 hover:text-rose-600 dark:text-slate-400 dark:hover:text-rose-400" aria-label="Sign out" title="Sign out">
                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                                        <path d="m16 17 5-5-5-5M21 12H9"/>
                                    </svg>
                                </button>
                            </form>
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

            @unless (request()->routeIs('chats.*'))
                <footer class="mx-auto w-full max-w-7xl px-4 pb-5 text-right sm:px-6">
                    <p class="text-[11px] text-slate-400 dark:text-slate-500">Developed by <span class="font-medium text-slate-600 dark:text-slate-300">Xylotech</span></p>
                </footer>
            @endunless
        </div>

        @include('partials.consent-modal')
    </body>
</html>