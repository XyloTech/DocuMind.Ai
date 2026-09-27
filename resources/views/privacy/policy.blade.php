<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full" data-theme>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="color-scheme" content="light dark">
        <meta name="description" content="{{ config('app.name') }} privacy policy — what we store, why we store it, and the controls you have over it.">

        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        <link rel="icon" type="image/png" sizes="500x500" href="{{ asset('favicon.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('logo.png') }}">

        <title>Privacy policy · {{ config('app.name') }}</title>

        <script>
            // Applied before first paint so a dark-mode reload never flashes white.
            (() => {
                const stored = localStorage.getItem('documind_theme');
                const dark = stored === 'dark'
                    || (!stored && window.matchMedia('(prefers-color-scheme: dark)').matches);

                document.documentElement.classList.toggle('dark', dark);
            })();
        </script>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>

    <body class="h-full bg-slate-50 text-slate-900 antialiased selection:bg-indigo-500 selection:text-white dark:bg-black dark:text-white">
        <header class="sticky top-0 z-30 border-b border-slate-200/80 bg-white/95 backdrop-blur-md dark:border-white/10 dark:bg-black/90">
            <div class="mx-auto flex h-14 max-w-3xl items-center justify-between gap-4 px-4 sm:px-6">
                <a href="{{ route('home') }}" class="group flex items-center gap-2.5 text-sm font-semibold tracking-tight text-slate-900 dark:text-white">
                    <x-brand-mark :size="32" class="transition-transform group-hover:scale-105" />
                    <span>{{ config('app.name') }}</span>
                </a>

                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        data-theme-toggle
                        aria-label="Toggle dark mode"
                        class="btn btn-secondary btn-icon btn-sm"
                    >
                        <svg class="h-4 w-4 dark:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" />
                        </svg>
                        <svg class="hidden h-4 w-4 dark:block text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                        </svg>
                    </button>

                    <a
                        href="{{ route('login') }}"
                        class="btn btn-secondary btn-sm"
                    >
                        Sign in
                    </a>
                </div>
            </div>
        </header>

        <main class="mx-auto w-full max-w-3xl px-4 py-10 sm:px-6">
            <span class="inline-flex items-center gap-1.5 rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-[11px] font-semibold text-indigo-600 dark:border-indigo-500/20 dark:bg-indigo-500/10 dark:text-indigo-300">
                Privacy
            </span>

            <h1 class="mt-3 text-3xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                Privacy policy
            </h1>

            <p class="mt-2 text-sm text-slate-500 dark:text-slate-500">
                Last updated {{ \Illuminate\Support\Carbon::parse(config('privacy.consent_version'))->format('d F Y') }}
            </p>

            <div class="mt-8 space-y-8 text-sm leading-relaxed text-slate-600 dark:text-slate-600">
                <section>
                    <h2 class="text-base font-bold text-slate-900 dark:text-white">What we store</h2>
                    <ul class="mt-2 list-disc space-y-1.5 pl-5">
                        <li>Your account details (name and email address) and your credit balance.</li>
                        <li>The documents you upload, their text and the page citations used to answer you.</li>
                        <li>Your conversations — but only if you switch on <strong>Save my chat history</strong>.</li>
                        <li>Your privacy choices and an activity trail of changes you make here.</li>
                    </ul>
                </section>

                <section>
                    <h2 class="text-base font-bold text-slate-900 dark:text-white">What we never do</h2>
                    <ul class="mt-2 list-disc space-y-1.5 pl-5">
                        <li>We never sell your data or share it with advertisers.</li>
                        <li>We never use your email address, name or other account details for training.</li>
                        <li>We never train on your conversations unless you have explicitly switched that on.</li>
                        <li>We never keep a conversation you have asked us to delete.</li>
                    </ul>
                </section>

                <section>
                    <h2 class="text-base font-bold text-slate-900 dark:text-white">Training and anonymisation</h2>
                    <p class="mt-2">
                        If you opt in, only anonymised text is used. Emails, names, phone numbers, card
                        numbers, IP addresses, passwords and API keys are replaced with placeholders such as
                        <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs font-semibold text-slate-700 dark:bg-white/10 dark:text-slate-700">[EMAIL]</code>
                        before anything leaves your account. You can withdraw this at any time, and withdrawal
                        stops all future use immediately.
                    </p>
                </section>

                <section>
                    <h2 class="text-base font-bold text-slate-900 dark:text-white">Retention and deletion</h2>
                    <p class="mt-2">
                        Choose a retention window in your privacy settings and conversations older than that
                        are purged automatically. <strong>Delete my data</strong> permanently removes your
                        conversations, messages, uploaded files and widget sites straight away — you keep your
                        account, and the request is recorded in your activity trail. You can also
                        <strong>export a copy</strong> of everything we hold in a portable JSON file.
                    </p>
                </section>

                <section>
                    <h2 class="text-base font-bold text-slate-900 dark:text-white">Security</h2>
                    <p class="mt-2">
                        Traffic is encrypted with HTTPS. Sensitive records are encrypted at rest, access is
                        scoped to your signed-in account, administrative access is separately permissioned, and
                        privacy actions are audited. Deletion and consent changes require you to be signed in,
                        and deletion additionally requires your password.
                    </p>
                </section>

                <section>
                    <h2 class="text-base font-bold text-slate-900 dark:text-white">Your controls</h2>
                    <p class="mt-2">
                        Everything above is controlled from <strong>Privacy &amp; data</strong> in the app:
                        switch storage or training on and off, set retention, withdraw consent, export your
                        data or delete it permanently. Changes take effect immediately and are confirmed on
                        screen.
                    </p>
                </section>

                <section>
                    <h2 class="text-base font-bold text-slate-900 dark:text-white">Contact</h2>
                    <p class="mt-2">
                        Questions about this policy or your data? Sign in and use the controls on the privacy
                        page, or contact your administrator.
                    </p>
                </section>
            </div>

            <div class="mt-10 flex flex-wrap gap-3">
                <a
                    href="{{ route('login') }}"
                    class="btn btn-primary inline-flex items-center gap-2"
                >
                    Back to {{ config('app.name') }}
                </a>
            </div>
        </main>
    </body>
</html>
