<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>@yield('title', config('app.name'))</title>

        <meta name="color-scheme" content="light dark">
        <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#05060b" media="(prefers-color-scheme: dark)">
        <meta name="description" content="Sign in to {{ config('app.name') }} — AI customer support, grounded in your approved sources.">

        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        <link rel="icon" type="image/png" sizes="500x500" href="{{ asset('favicon.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('logo.png') }}">

        <script>
            // Applied before first paint so a dark-mode reload never flashes white.
            (() => {
                const stored = localStorage.getItem('documind_theme');
                const dark = stored === 'dark'
                    || (!stored && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.classList.toggle('dark', dark);
            })();
        </script>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="auth-page bg-white text-slate-900 antialiased selection:bg-indigo-500 selection:text-white dark:bg-[#05060b] dark:text-slate-100">
        {{-- Static backdrop: three soft glows under a dot grid that fades out
             around the card. Decorative only — nothing here animates. --}}
        <div class="auth-bg" aria-hidden="true">
            <span class="auth-glow auth-glow-indigo"></span>
            <span class="auth-glow auth-glow-violet"></span>
            <span class="auth-glow auth-glow-emerald"></span>
            <span class="auth-grid"></span>
        </div>

        <button
            type="button"
            data-theme-toggle
            aria-label="Toggle dark mode"
            class="btn btn-secondary btn-icon btn-sm absolute right-4 top-4 z-30"
        >
            <svg class="h-4 w-4 dark:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
            </svg>
            <svg class="hidden h-4 w-4 text-amber-400 dark:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" />
            </svg>
        </button>

        <div class="relative z-10 flex min-h-screen w-full flex-col items-center justify-center px-4 py-14">
            <a href="{{ route('home') }}" class="group mb-10 flex items-center gap-2.5">
                <x-brand-mark :size="40" class="transition-transform group-hover:scale-105" />
                <span class="text-base font-bold tracking-tight text-slate-900 dark:text-white">{{ config('app.name') }}</span>
            </a>

            <div class="w-full max-w-md">
                <div class="mb-4 text-center [&_ul]:list-none [&_ul]:pl-0">
                    @include('partials.flash')
                </div>

                <div class="auth-card">
                    @yield('content')
                </div>

                <p class="mt-6 text-center text-xs leading-6 text-slate-500 dark:text-slate-400">
                    By continuing you agree to our
                    <a href="{{ route('privacy.policy') }}" class="font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300">Privacy&nbsp;policy</a>.
                </p>
            </div>
        </div>
    </body>
</html>
