<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full" data-theme>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="color-scheme" content="light dark">
        <meta name="description" content="{{ config('app.name') }} is the customizable AI customer-support platform. Connect your product information, FAQs, policies, terms and documentation, and embed an assistant that answers only from those approved sources — grounded answers, human handoff, and full analytics.">
        <meta name="theme-color" content="#05060b" media="(prefers-color-scheme: dark)">
        <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
        <link rel="canonical" href="{{ url('/') }}">

        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ config('app.name') }}">
        <meta property="og:title" content="{{ config('app.name') }} — Support that answers from your knowledge">
        <meta property="og:description" content="An AI customer-support assistant grounded in your approved sources: product information, FAQs, policies, terms and docs. Embed it on any site in minutes.">
        <meta property="og:url" content="{{ url('/') }}">
        <meta property="og:image" content="{{ asset('logo.png') }}">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ config('app.name') }} — Support that answers from your knowledge">
        <meta name="twitter:description" content="An AI customer-support assistant grounded in your approved sources. Embed it on any site in minutes.">
        <meta name="twitter:image" content="{{ asset('logo.png') }}">

        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        <link rel="icon" type="image/png" sizes="500x500" href="{{ asset('favicon.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('logo.png') }}">

        <title>{{ config('app.name') }} — Support that answers from your knowledge</title>

        <script type="application/ld+json">{!! json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'SoftwareApplication',
            'name' => config('app.name'),
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web',
            'description' => 'AI customer-support assistant that answers visitors from your approved knowledge sources — product information, FAQs, policies, terms and documentation.',
            'offers' => [
                ['@type' => 'Offer', 'name' => 'Starter', 'price' => '0', 'priceCurrency' => 'USD'],
                ['@type' => 'Offer', 'name' => 'Pro', 'price' => '29', 'priceCurrency' => 'USD'],
                ['@type' => 'Offer', 'name' => 'Scale', 'price' => '99', 'priceCurrency' => 'USD'],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>

        <script>
            // Dark-first: the marketing page opens dark unless the visitor has
            // explicitly chosen light before, so a reload never flashes white.
            (() => {
                const stored = localStorage.getItem('documind_theme');

                document.documentElement.classList.toggle('dark', stored !== 'light');
            })();
        </script>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>

    <body data-landing class="h-full bg-white text-slate-900 antialiased selection:bg-indigo-500 selection:text-white dark:bg-[#05060b] dark:text-slate-100">
        {{-- ── Navigation ────────────────────────────────────────────── --}}
        <header class="sticky top-0 z-40 border-b border-slate-200/80 bg-white/85 backdrop-blur-lg dark:border-white/10 dark:bg-black/70">
            <div class="mx-auto flex h-16 max-w-7xl items-center justify-between gap-3 px-4 sm:px-6 lg:px-8">
                <a href="{{ route('home') }}" class="group flex items-center gap-2.5">
                    <x-brand-mark :size="32" class="transition-transform group-hover:scale-105" />
                    <span class="text-sm font-bold tracking-tight text-slate-900 dark:text-white">{{ config('app.name') }}</span>
                </a>

                <nav class="hidden items-center gap-1 md:flex" aria-label="Primary">
                    <a href="#product" class="btn btn-ghost btn-sm">How it works</a>
                    <a href="#features" class="btn btn-ghost btn-sm">Features</a>
                    <a href="#use-cases" class="btn btn-ghost btn-sm">Use cases</a>
                    <a href="#pricing" class="btn btn-ghost btn-sm">Pricing</a>
                    <a href="#faq" class="btn btn-ghost btn-sm">FAQ</a>
                </nav>

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
                        <svg class="hidden h-4 w-4 text-amber-400 dark:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591-1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                        </svg>
                    </button>

                    <a href="{{ route('login') }}" class="btn btn-secondary btn-sm hidden sm:inline-flex">Sign in</a>
                    <a href="{{ route('login') }}" class="btn btn-primary btn-sm hidden sm:inline-flex">Create your support bot</a>

                    <button
                        type="button"
                        data-landing-menu-toggle
                        aria-expanded="false"
                        aria-controls="landing-menu"
                        aria-label="Open menu"
                        class="btn btn-secondary btn-icon btn-sm md:hidden"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16" />
                        </svg>
                    </button>
                </div>
            </div>

            <div id="landing-menu" data-landing-menu hidden class="border-t border-slate-200/80 bg-white px-4 py-3 md:hidden dark:border-white/10 dark:bg-black/90">
                <nav class="mx-auto flex max-w-7xl flex-col gap-1" aria-label="Mobile">
                    <a href="#product" class="btn btn-ghost justify-start">How it works</a>
                    <a href="#features" class="btn btn-ghost justify-start">Features</a>
                    <a href="#use-cases" class="btn btn-ghost justify-start">Use cases</a>
                    <a href="#pricing" class="btn btn-ghost justify-start">Pricing</a>
                    <a href="#faq" class="btn btn-ghost justify-start">FAQ</a>
                    <div class="mt-2 flex gap-2 border-t border-slate-200 pt-3 dark:border-white/10">
                        <a href="{{ route('login') }}" class="btn btn-secondary flex-1">Sign in</a>
                        <a href="{{ route('login') }}" class="btn btn-primary flex-1">Create your bot</a>
                    </div>
                </nav>
            </div>
        </header>

        <main>
            {{-- ── Hero ───────────────────────────────────────────────── --}}
            <section class="l-section relative overflow-hidden pb-12 lg:pb-16" id="top">
                <div class="l-glow left-[-10rem] top-[-8rem] h-[28rem] w-[28rem] bg-indigo-600/30" aria-hidden="true"></div>
                <div class="l-glow right-[-8rem] top-6 h-[26rem] w-[26rem] bg-cyan-500/20" aria-hidden="true"></div>
                <div class="l-glow bottom-[-12rem] left-1/3 h-[24rem] w-[24rem] bg-violet-600/20" aria-hidden="true"></div>
                <div class="l-glow left-[-7rem] top-[38%] h-[20rem] w-[20rem] bg-violet-500/15" aria-hidden="true"></div>
                <div class="l-glow right-[-7rem] top-[30%] h-[22rem] w-[22rem] bg-emerald-500/10" aria-hidden="true"></div>

                {{-- Side dressing: a dot texture that only shows in the margins,
                     plus two glass cards that flank the headline on wide screens. --}}
                <div class="l-hero-grid" aria-hidden="true"></div>

                <aside class="l-hero-side l-hero-side--left" aria-hidden="true">
                    <div class="l-hero-card l-float">
                        <div class="l-hero-card-head">
                            <span class="l-hero-card-title">Knowledge sources</span>
                            <span class="l-hero-pill"><i class="l-hero-dot"></i>4 live</span>
                        </div>
                        <ul class="l-hero-src">
                            <li>
                                <span class="l-hero-ico bg-indigo-500">
                                    <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 2h8l4 4v14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M14 2v5h4"/></svg>
                                </span>
                                <span class="l-hero-src-name">Product docs.pdf</span>
                                <svg class="l-hero-ok" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7"/></svg>
                            </li>
                            <li>
                                <span class="l-hero-ico bg-violet-500">
                                    <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 1 1-9-9 9 9 0 0 1 9 9Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M9.1 9.5a3 3 0 0 1 5.8 1c0 2-2.9 2.6-2.9 4"/><path stroke-linecap="round" d="M12 17.5h.01"/></svg>
                                </span>
                                <span class="l-hero-src-name">Help center FAQs</span>
                                <svg class="l-hero-ok" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7"/></svg>
                            </li>
                            <li>
                                <span class="l-hero-ico bg-cyan-500">
                                    <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10 14a5 5 0 0 0 7.1 0l2.4-2.4a5 5 0 0 0-7.1-7.1L11 5.9"/><path stroke-linecap="round" stroke-linejoin="round" d="M14 10a5 5 0 0 0-7.1 0L4.5 12.4a5 5 0 0 0 7.1 7.1L13 18.1"/></svg>
                                </span>
                                <span class="l-hero-src-name">/pricing &amp; plans</span>
                                <svg class="l-hero-ok" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7"/></svg>
                            </li>
                            <li>
                                <span class="l-hero-ico bg-emerald-500">
                                    <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3l8 3v6c0 4.6-3.2 7.7-8 9-4.8-1.3-8-4.4-8-9V6l8-3Z"/></svg>
                                </span>
                                <span class="l-hero-src-name">Terms &amp; policies</span>
                                <svg class="l-hero-ok" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7"/></svg>
                            </li>
                        </ul>
                    </div>

                    <div class="l-hero-card l-float l-float-2">
                        <div class="l-hero-card-head">
                            <span class="l-hero-card-title">Index health</span>
                            <span class="l-hero-pill"><i class="l-hero-dot"></i>Synced 2m ago</span>
                        </div>
                        <div class="l-hero-spark" aria-hidden="true">
                            <i style="--h: 44%"></i>
                            <i style="--h: 71%"></i>
                            <i style="--h: 53%"></i>
                            <i style="--h: 86%"></i>
                            <i style="--h: 68%"></i>
                            <i style="--h: 100%"></i>
                        </div>
                        <div class="l-hero-kv">
                            <span><strong>1,284</strong> passages</span>
                            <span><strong>12</strong> sources</span>
                        </div>
                    </div>
                </aside>

                <aside class="l-hero-side l-hero-side--right" aria-hidden="true">
                    <div class="l-hero-card l-float l-float-2">
                        <div class="l-hero-card-head">
                            <span class="flex items-center gap-1.5">
                                <x-blobatar :name="'DocuMind'" :size="22" data-blobatar-expression="happy" />
                                <span class="l-hero-card-title">DocuMind AI</span>
                            </span>
                            <span class="l-hero-pill">
                                <svg class="h-2.5 w-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7"/></svg>
                                Grounded
                            </span>
                        </div>
                        <p class="l-hero-answer">
                            Yes — orders to <strong>Canada</strong> arrive in
                            <strong>3–5 business days</strong> with full tracking from dispatch.
                        </p>
                        <span class="l-hero-cite">
                            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 2h8l4 4v14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M14 2v5h4"/></svg>
                            Product docs · Shipping policy
                        </span>
                    </div>

                    <div class="l-hero-card l-float">
                        <div class="l-hero-card-head">
                            <span class="flex items-center gap-1.5">
                                <x-blobatar :name="'Sarah'" :size="22" data-blobatar-expression="happy" />
                                <span class="l-hero-card-title">Sarah joined the chat</span>
                            </span>
                            <span class="l-hero-pill"><i class="l-hero-dot"></i>12s</span>
                        </div>
                        <p class="l-hero-answer">
                            Handoff taken — the visitor's question, transcript and every
                            <strong>cited source</strong> came along.
                        </p>
                        <div class="l-hero-kv">
                            <span class="l-hero-rate">
                                <svg class="h-3 w-3" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M11.48 3.5a.56.56 0 0 1 1.04 0l2.12 4.72 5.12.53a.56.56 0 0 1 .31.96l-3.83 3.45 1.09 5.05a.56.56 0 0 1-.82.59L12 16.31l-4.56 2.79a.56.56 0 0 1-.82-.59l1.09-5.05-3.83-3.45a.56.56 0 0 1 .31-.96l5.12-.53 2.17-4.72Z"/></svg>
                                <strong>4.8</strong> / 5
                            </span>
                            <span>312 ratings</span>
                        </div>
                    </div>
                </aside>

                <div class="relative mx-auto max-w-4xl px-4 pb-6 pt-14 text-center sm:px-6 lg:pt-20">
                    <div class="reveal">
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-600 dark:border-indigo-500/25 dark:bg-indigo-500/10 dark:text-indigo-300">
                            <span class="relative flex h-1.5 w-1.5" aria-hidden="true">
                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-indigo-400 opacity-75"></span>
                                <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-indigo-500"></span>
                            </span>
                            AI customer support, grounded in your approved sources
                        </span>

                        <h1 class="mt-5 text-4xl font-extrabold leading-[1.05] tracking-tight sm:text-5xl lg:text-6xl">
                            Support that answers
                            <span class="l-grad-text">
                                from your
                                <span
                                    class="l-h1-blob"
                                    data-blobatar-name="DocuMind"
                                    data-blobatar-background="circle"
                                    data-blobatar-animate="live"
                                    data-blobatar-expression="happy"
                                    data-blobatar-follow="true"
                                    aria-hidden="true"
                                ></span>
                                knowledge.
                            </span>
                        </h1>

                        <p class="mx-auto mt-5 max-w-2xl text-base leading-relaxed text-slate-600 sm:text-lg dark:text-slate-400">
                            Connect your product information, FAQs, policies, terms and documentation to
                            {{ config('app.name') }} — the AI support assistant that answers visitors only from
                            those approved sources, in your brand voice, embeddable on any site in minutes.
                        </p>
                    </div>

                    {{-- The complete workflow, at a glance --}}
                    <ol class="reveal l-flow mx-auto mt-9 max-w-3xl" aria-label="The complete workflow">
                        <li class="l-flow-step">
                            <span class="l-flow-num" aria-hidden="true">1</span>
                            <span class="l-flow-body">
                                <strong>Provide your knowledge</strong>
                                <small>Docs, FAQs, policies, terms</small>
                            </span>
                        </li>
                        <li class="l-flow-step">
                            <span class="l-flow-num" aria-hidden="true">2</span>
                            <span class="l-flow-body">
                                <strong>Configure the assistant</strong>
                                <small>Name, tone, greeting</small>
                            </span>
                        </li>
                        <li class="l-flow-step">
                            <span class="l-flow-num" aria-hidden="true">3</span>
                            <span class="l-flow-body">
                                <strong>Embed the widget</strong>
                                <small>One script tag, any site</small>
                            </span>
                        </li>
                        <li class="l-flow-step">
                            <span class="l-flow-num" aria-hidden="true">4</span>
                            <span class="l-flow-body">
                                <strong>Support your customers</strong>
                                <small>Cited answers, human handoff</small>
                            </span>
                        </li>
                    </ol>

                    <div class="reveal mt-9 flex flex-wrap items-center justify-center gap-3">
                        <a href="{{ route('login') }}" class="btn btn-primary h-11 px-5 text-[0.95rem]">
                            Create your support bot
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12l-7.5 7.5M3 12h17" />
                            </svg>
                        </a>
                        <a href="#demo" class="btn btn-secondary h-11 px-5 text-[0.95rem]">View live demo</a>
                        <a href="{{ route('login') }}" class="btn btn-ghost h-11 px-4 text-[0.95rem]">Start free</a>
                    </div>

                    <ul class="reveal mt-7 flex flex-wrap justify-center gap-x-5 gap-y-2 text-xs font-medium text-slate-500 sm:text-sm dark:text-slate-400">
                        <li class="flex items-center gap-1.5">
                            <svg class="h-4 w-4 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" /></svg>
                            5-minute setup
                        </li>
                        <li class="flex items-center gap-1.5">
                            <svg class="h-4 w-4 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" /></svg>
                            One-line install
                        </li>
                        <li class="flex items-center gap-1.5">
                            <svg class="h-4 w-4 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" /></svg>
                            Grounded, cited answers
                        </li>
                        <li class="flex items-center gap-1.5">
                            <svg class="h-4 w-4 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" /></svg>
                            No credit card
                        </li>
                    </ul>
                </div>

                {{-- Integrated product demo: a muted, looping tour with live moments --}}
                <div class="relative mx-auto mt-10 max-w-6xl px-4 sm:px-6 lg:px-8" data-showcase>
                    <div class="reveal l-showcase" data-showcase-frame data-video-state="paused" data-popups-state="on">
                        <div class="l-showcase-bar">
                            <span class="h-2.5 w-2.5 rounded-full bg-rose-400" aria-hidden="true"></span>
                            <span class="h-2.5 w-2.5 rounded-full bg-amber-400" aria-hidden="true"></span>
                            <span class="h-2.5 w-2.5 rounded-full bg-emerald-400" aria-hidden="true"></span>
                            <span class="l-showcase-url" aria-hidden="true">yoursite.com — with {{ config('app.name') }} installed</span>

                            <span class="l-showcase-controls">
                                <button type="button" data-video-toggle class="l-vctrl" aria-label="Pause the demo video">
                                    <svg class="l-vctrl-pause h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M9 5v14M15 5v14" /></svg>
                                    <svg class="l-vctrl-play h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 4.5v15l13-7.5-13-7.5Z" /></svg>
                                </button>
                                <button type="button" data-video-mute class="l-vctrl" aria-label="Unmute the demo video">
                                    <svg class="l-vctrl-muted h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5 6.75 8.5H3v7h3.75L11 19V5Zm5.5 4.5 4 5m0-5-4 5" /></svg>
                                    <svg class="l-vctrl-sound h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5 6.75 8.5H3v7h3.75L11 19V5Zm4.25 3.75a5 5 0 0 1 0 6.5M17.5 6a8 8 0 0 1 0 12" /></svg>
                                </button>
                                <button type="button" data-video-replay class="l-vctrl" aria-label="Replay the demo video from the start">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12a7.5 7.5 0 1 1 2.2 5.3M4.5 12V7.5m0 4.5H9" /></svg>
                                </button>
                            </span>
                        </div>

                        <video
                            data-showcase-video
                            class="l-showcase-video"
                            muted
                            loop
                            playsinline
                            preload="none"
                            poster="{{ asset('logo.png') }}"
                            aria-label="How {{ config('app.name') }} works: uploading knowledge, configuring the assistant, embedding the widget, and handling a customer conversation"
                        >
                            <source src="{{ asset('videos/how-it-works.mp4') }}" type="video/mp4">
                            Your browser doesn't support HTML5 video —
                            <a href="{{ asset('videos/how-it-works.mp4') }}">download the walkthrough</a> instead.
                        </video>

                        {{-- Floating support moments around the tour --}}
                        <div class="l-popups" data-popups data-popups-state="on">
                            <div class="l-pop l-pop--tl" data-popup>
                                <div class="l-pop-head">
                                    <span class="l-pop-visitor" aria-hidden="true">S</span>
                                    <span>
                                        <strong>Visitor</strong>
                                        <small>/pricing</small>
                                    </span>
                                    <button type="button" data-popup-close class="l-pop-close" aria-label="Close this moment">
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18" /></svg>
                                    </button>
                                </div>
                                <p class="l-pop-text">Do you ship to Canada — and how long does it take?</p>
                            </div>

                            <div class="l-pop l-pop--tr" data-popup>
                                <div class="l-pop-head">
                                    <x-blobatar :name="'DocuMind'" :size="26" data-blobatar-expression="happy" data-blobatar-animate="live" data-blobatar-follow="true" />
                                    <span>
                                        <strong>Sonic Support</strong>
                                        <small>answered from your sources</small>
                                    </span>
                                    <button type="button" data-popup-close class="l-pop-close" aria-label="Close this moment">
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18" /></svg>
                                    </button>
                                </div>
                                <p class="l-pop-text">Yes — standard shipping to Canada takes 3–5 business days, fully tracked.</p>
                                <div class="l-pop-meta">
                                    <span class="l-src">Shipping policy · §2</span>
                                    <span class="l-src l-src-ok">Grounded</span>
                                </div>
                            </div>

                            <div class="l-pop l-pop--bl" data-popup>
                                <div class="l-pop-head">
                                    <x-blobatar :name="'DocuMind'" :size="26" data-blobatar-expression="thinking" data-blobatar-animate="live" />
                                    <span>
                                        <strong>Assistant is typing</strong>
                                        <small>matching the question to approved docs</small>
                                    </span>
                                    <button type="button" data-popup-close class="l-pop-close" aria-label="Close this moment">
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18" /></svg>
                                    </button>
                                </div>
                                <p class="l-pop-text"><span class="l-typing text-indigo-500" aria-hidden="true"><i></i><i></i><i></i></span></p>
                            </div>

                            <div class="l-pop l-pop--br" data-popup>
                                <div class="l-pop-head">
                                    <x-blobatar :name="'DocuMind'" :size="26" data-blobatar-expression="love" data-blobatar-animate="live" />
                                    <span>
                                        <strong>Email capture</strong>
                                        <small>consent-gated lead</small>
                                    </span>
                                    <button type="button" data-popup-close class="l-pop-close" aria-label="Close this moment">
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18" /></svg>
                                    </button>
                                </div>
                                <p class="l-pop-text">Leave your email and the team will follow up on this conversation.</p>
                                <div class="l-pop-meta">
                                    <span class="l-src">sam@acme.io · consented</span>
                                </div>
                            </div>

                            <div class="l-pop l-pop--ml" data-popup>
                                <div class="l-pop-head">
                                    <x-blobatar :name="'Ravi'" :size="26" />
                                    <span>
                                        <strong>Human handoff</strong>
                                        <small>support action</small>
                                    </span>
                                    <button type="button" data-popup-close class="l-pop-close" aria-label="Close this moment">
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18" /></svg>
                                    </button>
                                </div>
                                <p class="l-pop-text">Escalated to a human — full transcript attached. Ravi replies in 2 min.</p>
                            </div>
                        </div>

                        {{-- Attached player footer: caption + moment controls --}}
                        <div class="l-showcase-caption">
                            <p>
                                A muted, looping tour of the product — play, pause, unmute or replay it, and watch
                                real support moments play out around it.
                            </p>
                            <span class="l-showcase-actions">
                                <button type="button" data-popups-replay class="btn btn-secondary btn-sm">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12a7.5 7.5 0 1 1 2.2 5.3M4.5 12V7.5m0 4.5H9" /></svg>
                                    Replay moments
                                </button>
                                <button type="button" data-popups-dismiss class="btn btn-ghost btn-sm">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18" /></svg>
                                    Close moments
                                </button>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            {{-- ── Social proof: the teams that ship with it ─────────── --}}
            <section class="bg-slate-50/60 dark:bg-white/[0.015]" aria-label="Trusted by">
                <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6 lg:px-8">
                    <p class="reveal text-center text-[11px] font-bold uppercase tracking-[0.22em] text-slate-400 dark:text-slate-500">
                        Trusted by product &amp; development teams
                    </p>

                    <ul class="reveal mt-6 flex flex-wrap items-center justify-center gap-x-10 gap-y-6 sm:gap-x-14">
                        <li class="l-logo" style="--brand: #12b76a">
                            <span class="l-logo-word l-logo-word--tight"><span class="l-logo-accent">X</span>ylo</span>
                        </li>
                        <li class="l-logo" style="--brand: #6366f1">
                            <svg class="l-logo-mark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                <circle cx="12" cy="12" r="9"/>
                                <path d="m15.5 8.5-2.1 5-4.9 2 2.1-5 4.9-2Z" fill="currentColor" stroke="none"/>
                            </svg>
                            <span class="l-logo-word l-logo-word--wide">Northbeam</span>
                        </li>
                        <li class="l-logo" style="--brand: #f59e0b">
                            <svg class="l-logo-mark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M12 2.6 20 7.2v9.6l-8 4.6-8-4.6V7.2l8-4.6Z"/>
                            </svg>
                            <span class="l-logo-word l-logo-word--lower">kairo<span class="l-logo-sub">labs</span></span>
                        </li>
                        <li class="l-logo" style="--brand: #ec4899">
                            <svg class="l-logo-mark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                <path d="M12 3 20 7.5v9L12 21l-8-4.5v-9L12 3Z"/>
                                <path d="M12 12 20 7.5M12 12v9M12 12 4 7.5"/>
                            </svg>
                            <span class="l-logo-word">Hexa</span>
                        </li>
                        <li class="l-logo" style="--brand: #8b5cf6">
                            <svg class="l-logo-mark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <circle cx="12" cy="12" r="8.5"/>
                                <circle cx="12" cy="12" r="3" fill="currentColor" stroke="none"/>
                            </svg>
                            <span class="l-logo-word l-logo-word--round">Truffle</span>
                        </li>
                        <li class="l-logo" style="--brand: #0ea5e9">
                            <svg class="l-logo-mark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M12 4.5 20.5 19.5h-17L12 4.5Z"/>
                            </svg>
                            <span class="l-logo-word l-logo-word--wide">Vertex</span>
                        </li>
                        <li class="l-logo" style="--brand: #f97316">
                            <svg class="l-logo-mark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                <circle cx="12" cy="12" r="4.2" fill="currentColor" stroke="none"/>
                                <path d="M12 2.4v2.6M12 19v2.6M2.4 12h2.6M19 12h2.6M5.2 5.2 7 7M17 17l1.8 1.8M18.8 5.2 17 7M7 17l-1.8 1.8" stroke-linecap="round"/>
                            </svg>
                            <span class="l-logo-word l-logo-word--light">lumen</span>
                        </li>
                        <li class="l-logo" style="--brand: #14b8a6">
                            <svg class="l-logo-mark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <rect x="3.5" y="3.5" width="17" height="17" rx="4.5"/>
                                <path d="m8 15.5 4-7.5 4 7.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <span class="l-logo-word l-logo-word--mono">foundry</span>
                        </li>
                    </ul>
                </div>
            </section>

            {{-- ── Metrics strip ──────────────────────────────────────── --}}
            <section class="border-y border-slate-200/80 bg-slate-50/70 dark:border-white/10 dark:bg-white/[0.02]" aria-label="Key numbers">
                <dl class="mx-auto grid max-w-7xl grid-cols-2 gap-6 px-4 py-8 sm:px-6 md:grid-cols-4 lg:px-8">
                    <div class="reveal text-center md:text-left">
                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Time to first answer</dt>
                        <dd class="mt-1 text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl dark:text-white">5 min</dd>
                    </div>
                    <div class="reveal text-center md:text-left">
                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">To install</dt>
                        <dd class="mt-1 text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl dark:text-white">1 line</dd>
                    </div>
                    <div class="reveal text-center md:text-left">
                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Answer grounding</dt>
                        <dd class="mt-1 text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl dark:text-white">100%</dd>
                    </div>
                    <div class="reveal text-center md:text-left">
                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Coverage</dt>
                        <dd class="mt-1 text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl dark:text-white">24/7</dd>
                    </div>
                </dl>
            </section>

            {{-- ── Interactive chat preview ───────────────────────────── --}}
            <section class="l-section relative overflow-hidden py-16 lg:py-24" id="preview">
                <div class="l-glow right-[-10rem] top-1/4 h-[26rem] w-[26rem] bg-indigo-600/15" aria-hidden="true"></div>

                <div class="relative mx-auto grid max-w-7xl items-center gap-12 px-4 sm:px-6 lg:grid-cols-2 lg:gap-16 lg:px-8">
                    <div class="reveal">
                        <span class="text-xs font-bold uppercase tracking-[0.18em] text-indigo-500">Live preview</span>
                        <h2 class="mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl">
                            Ask it what your customers ask.
                        </h2>
                        <p class="mt-4 max-w-lg text-base leading-relaxed text-slate-600 dark:text-slate-400">
                            Every answer is stitched from the documents you approved — product, policy,
                            FAQ or support escalation. Click a question and watch the assistant think.
                        </p>

                        <ul class="mt-6 space-y-3 text-sm text-slate-600 dark:text-slate-400">
                            <li class="flex gap-3">
                                <span class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-lg bg-indigo-500/10 text-indigo-500" aria-hidden="true">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m6-6H6" /></svg>
                                </span>
                                Answers cite the exact page they came from.
                            </li>
                            <li class="flex gap-3">
                                <span class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-lg bg-indigo-500/10 text-indigo-500" aria-hidden="true">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM4 21v-1a6 6 0 0 1 12 0v1" /></svg>
                                </span>
                                Escalates to a human with the full transcript attached.
                            </li>
                            <li class="flex gap-3">
                                <span class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-lg bg-indigo-500/10 text-indigo-500" aria-hidden="true">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v3m0 12v3m9-9h-3M6 12H3m14.07-6.07-2.12 2.12M9.05 14.95l-2.12 2.12m12.14 0-2.12-2.12M9.05 9.05 6.93 6.93" /></svg>
                                </span>
                                Declines politely when the answer isn't in your docs.
                            </li>
                        </ul>
                    </div>

                    <div class="reveal l-panel overflow-hidden" data-chat-preview>
                        <div class="flex items-center gap-3 border-b border-slate-200/80 px-4 py-3 dark:border-white/10">
                            <x-blobatar :name="'DocuMind'" :size="36" data-blobatar-expression="happy" />
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold text-slate-900 dark:text-white">{{ config('app.name') }} Assistant</p>
                                <p class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                                    Online · answering from 12 approved documents
                                </p>
                            </div>
                        </div>

                        <div data-chat-log class="flex h-72 flex-col gap-3 overflow-y-auto px-4 py-4" role="log" aria-live="polite" aria-label="Demo conversation">
                            <div class="l-bubble l-bubble-bot self-start">
                                Hi! Ask me anything your customers ask — I only answer from this site's approved knowledge.
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2 border-t border-slate-200/80 px-4 py-3 dark:border-white/10">
                            <button type="button" class="l-chip" data-qa="product">What is {{ config('app.name') }}?</button>
                            <button type="button" class="l-chip" data-qa="policy">How do you handle data?</button>
                            <button type="button" class="l-chip" data-qa="faq">How do I install it?</button>
                            <button type="button" class="l-chip" data-qa="support">Talk to a human</button>
                        </div>
                    </div>
                </div>
            </section>

            {{-- ── Knowledge sources ─────────────────────────────────── --}}
            <section class="l-section relative overflow-hidden py-16 lg:py-24" id="knowledge">
                <div class="l-glow left-[-8rem] bottom-0 h-[24rem] w-[24rem] bg-cyan-500/15" aria-hidden="true"></div>

                <div class="relative mx-auto grid max-w-7xl items-center gap-12 px-4 sm:px-6 lg:grid-cols-2 lg:gap-16 lg:px-8">
                    <div class="reveal">
                        <span class="text-xs font-bold uppercase tracking-[0.18em] text-indigo-500">Approved knowledge</span>
                        <h2 class="mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl">
                            Connect the sources your team already trusts.
                        </h2>
                        <p class="mt-4 max-w-lg text-base leading-relaxed text-slate-600 dark:text-slate-400">
                            Point {{ config('app.name') }} at your product information, FAQs, policies, terms,
                            documentation and any other approved sources. The assistant answers from those
                            sources only — cites the exact page, and says so when the answer isn't there.
                        </p>

                        <div class="mt-6 flex flex-wrap gap-2" role="group" aria-label="Example knowledge sources — click to toggle">
                            <button type="button" class="l-chip" data-source-chip aria-pressed="true">Product information</button>
                            <button type="button" class="l-chip" data-source-chip aria-pressed="true">FAQs</button>
                            <button type="button" class="l-chip" data-source-chip aria-pressed="true">Policies</button>
                            <button type="button" class="l-chip" data-source-chip aria-pressed="true">Terms</button>
                            <button type="button" class="l-chip" data-source-chip aria-pressed="true">Documentation</button>
                            <button type="button" class="l-chip" data-source-chip aria-pressed="false">Help center</button>
                            <button type="button" class="l-chip" data-source-chip aria-pressed="false">Release notes</button>
                        </div>

                        <div class="mt-6 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 dark:border-indigo-500/25 dark:bg-indigo-500/10">
                            <p class="text-sm font-semibold leading-relaxed text-indigo-700 dark:text-indigo-300">
                                Not a PDF reader — a support assistant. Documents are chunked and indexed so the
                                assistant can answer customers, not so you can browse files.
                            </p>
                        </div>
                    </div>

                    <div class="reveal l-panel overflow-hidden" aria-label="Knowledge sources panel">
                        <div class="flex items-center justify-between gap-3 border-b border-slate-200/80 px-5 py-3.5 dark:border-white/10">
                            <p class="text-sm font-bold text-slate-900 dark:text-white">Knowledge sources</p>
                            <span class="rounded-full bg-emerald-500/10 px-2.5 py-1 text-[11px] font-bold text-emerald-600 dark:text-emerald-400" data-source-count>5 connected</span>
                        </div>

                        <ul class="divide-y divide-slate-200/80 dark:divide-white/10">
                            <li class="l-kb-row"><span class="l-file-icon" aria-hidden="true">PDF</span><span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-800 dark:text-slate-200">Product-handbook.pdf</span><span class="text-xs font-semibold text-emerald-500">Indexed</span></li>
                            <li class="l-kb-row"><span class="l-file-icon" aria-hidden="true">FAQ</span><span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-800 dark:text-slate-200">help.acme.com/faq</span><span class="text-xs font-semibold text-emerald-500">Synced</span></li>
                            <li class="l-kb-row"><span class="l-file-icon" aria-hidden="true">DOC</span><span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-800 dark:text-slate-200">Refund-policy.docx</span><span class="text-xs font-semibold text-emerald-500">Indexed</span></li>
                            <li class="l-kb-row"><span class="l-file-icon" aria-hidden="true">URL</span><span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-800 dark:text-slate-200">acme.com/terms</span><span class="text-xs font-semibold text-emerald-500">Synced</span></li>
                            <li class="l-kb-row"><span class="l-file-icon" aria-hidden="true">MD</span><span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-800 dark:text-slate-200">developer-docs/</span><span class="text-xs font-semibold text-indigo-500">Chunking…</span></li>
                        </ul>

                        <div class="flex items-center gap-3 border-t border-slate-200/80 px-5 py-4 dark:border-white/10">
                            <x-blobatar :name="'DocuMind'" :size="34" data-blobatar-expression="smug" data-blobatar-animate="live" />
                            <p class="text-xs font-medium leading-relaxed text-slate-600 dark:text-slate-400">
                                "I only answer from what you've approved — and I always show my sources."
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            {{-- ── Five-step scroll story ─────────────────────────────── --}}
            <section class="l-section border-y border-slate-200/80 bg-slate-50/60 py-16 dark:border-white/10 dark:bg-white/[0.015] lg:py-24" id="product">
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="reveal mx-auto max-w-2xl text-center">
                        <span class="text-xs font-bold uppercase tracking-[0.18em] text-indigo-500">How it works</span>
                        <h2 class="mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl">
                            From documents to a live assistant in five steps.
                        </h2>
                        <p class="mt-4 text-base leading-relaxed text-slate-600 dark:text-slate-400">
                            No vector-database homework, no prompt-engineering degree. Upload your knowledge,
                            configure, customize, embed — then let it support your customers.
                        </p>
                    </div>

                    <div class="mt-14 grid gap-10 lg:grid-cols-2 lg:gap-16" data-story>
                        <ol class="flex flex-col gap-4">
                            <li>
                                <button type="button" data-story-step class="l-step w-full text-left" data-active="true" aria-current="step">
                                    <span class="l-step-index">1</span>
                                    <span class="min-w-0">
                                        <span class="block text-base font-bold text-slate-900 dark:text-white">Upload your knowledge</span>
                                        <span class="mt-1 block text-sm leading-relaxed text-slate-600 dark:text-slate-400">Product docs, FAQs, policies, terms, handbooks — the sources you'd approve an agent to use.</span>
                                    </span>
                                </button>
                            </li>
                            <li>
                                <button type="button" data-story-step class="l-step w-full text-left">
                                    <span class="l-step-index">2</span>
                                    <span class="min-w-0">
                                        <span class="block text-base font-bold text-slate-900 dark:text-white">Configure your assistant</span>
                                        <span class="mt-1 block text-sm leading-relaxed text-slate-600 dark:text-slate-400">Name, tone, greeting and model — your brand voice, not a generic bot.</span>
                                    </span>
                                </button>
                            </li>
                            <li>
                                <button type="button" data-story-step class="l-step w-full text-left">
                                    <span class="l-step-index">3</span>
                                    <span class="min-w-0">
                                        <span class="block text-base font-bold text-slate-900 dark:text-white">Customize the widget</span>
                                        <span class="mt-1 block text-sm leading-relaxed text-slate-600 dark:text-slate-400">Colors, launcher, greeting — and pick the Blobatar face your assistant wears.</span>
                                    </span>
                                </button>
                            </li>
                            <li>
                                <button type="button" data-story-step class="l-step w-full text-left">
                                    <span class="l-step-index">4</span>
                                    <span class="min-w-0">
                                        <span class="block text-base font-bold text-slate-900 dark:text-white">Embed anywhere</span>
                                        <span class="mt-1 block text-sm leading-relaxed text-slate-600 dark:text-slate-400">One script tag on any site — React, Next.js, Vue, Laravel or plain HTML.</span>
                                    </span>
                                </button>
                            </li>
                            <li>
                                <button type="button" data-story-step class="l-step w-full text-left">
                                    <span class="l-step-index">5</span>
                                    <span class="min-w-0">
                                        <span class="block text-base font-bold text-slate-900 dark:text-white">Support your customers</span>
                                        <span class="mt-1 block text-sm leading-relaxed text-slate-600 dark:text-slate-400">The widget goes live — cited answers 24/7, email capture, and human handoff when it matters.</span>
                                    </span>
                                </button>
                            </li>
                        </ol>

                        <div class="lg:sticky lg:top-24 lg:self-start">
                            <div class="l-story-panels reveal relative">
                                {{-- Step 1 — upload --}}
                                <div class="l-panel p-6" data-story-panel data-visible="true" aria-hidden="false">
                                    <div class="flex items-center justify-between gap-3">
                                        <p class="text-sm font-bold text-slate-900 dark:text-white">Knowledge base</p>
                                        <span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[11px] font-bold text-emerald-600 dark:text-emerald-400">4 files ready</span>
                                    </div>
                                    <ul class="mt-4 space-y-2.5">
                                        <li class="l-file"><span class="l-file-icon" aria-hidden="true">PDF</span><span class="flex-1 truncate text-sm font-medium">Product-handbook.pdf</span><span class="text-xs font-semibold text-emerald-500">Indexed</span></li>
                                        <li class="l-file"><span class="l-file-icon" aria-hidden="true">DOC</span><span class="flex-1 truncate text-sm font-medium">Refund-policy.docx</span><span class="text-xs font-semibold text-emerald-500">Indexed</span></li>
                                        <li class="l-file"><span class="l-file-icon" aria-hidden="true">PDF</span><span class="flex-1 truncate text-sm font-medium">Pricing-FAQ.pdf</span><span class="text-xs font-semibold text-emerald-500">Indexed</span></li>
                                        <li class="l-file"><span class="l-file-icon" aria-hidden="true">MD</span><span class="flex-1 truncate text-sm font-medium">support-scripts.md</span><span class="text-xs font-semibold text-indigo-500">Chunking…</span></li>
                                    </ul>
                                    <div class="mt-5 flex items-center gap-3 rounded-xl border border-indigo-200 bg-indigo-50 px-3 py-2.5 dark:border-indigo-500/25 dark:bg-indigo-500/10">
                                        <x-blobatar :name="'DocuMind'" :size="30" data-blobatar-expression="thinking" data-blobatar-animate="live" />
                                        <p class="text-xs font-medium text-indigo-700 dark:text-indigo-300">Reading and chunking your documents…</p>
                                    </div>
                                </div>

                                {{-- Step 2 — configure --}}
                                <div class="l-panel p-6" data-story-panel data-visible="false" aria-hidden="true">
                                    <p class="text-sm font-bold text-slate-900 dark:text-white">Assistant settings</p>
                                    <div class="mt-4 space-y-3">
                                        <label class="block">
                                            <span class="l-field-label">Assistant name</span>
                                            <span class="l-field-value">Sonic Support</span>
                                        </label>
                                        <label class="block">
                                            <span class="l-field-label">Tone</span>
                                            <span class="l-field-value">Friendly &amp; concise</span>
                                        </label>
                                        <label class="block">
                                            <span class="l-field-label">Greeting</span>
                                            <span class="l-field-value">"Hi! I know our docs inside out — what can I help you find?"</span>
                                        </label>
                                        <label class="block">
                                            <span class="l-field-label">Model</span>
                                            <span class="l-field-value">Balanced (default) · switchable anytime</span>
                                        </label>
                                    </div>
                                </div>

                                {{-- Step 3 — customize widget --}}
                                <div class="l-panel p-6" data-story-panel data-visible="false" aria-hidden="true">
                                    <p class="text-sm font-bold text-slate-900 dark:text-white">Widget appearance</p>
                                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                        <div>
                                            <span class="l-field-label">Accent color</span>
                                            <div class="mt-1.5 flex gap-2" aria-hidden="true">
                                                <span class="h-6 w-6 rounded-full bg-indigo-500 ring-2 ring-indigo-400 ring-offset-2 ring-offset-white dark:ring-offset-[#0b0d14]"></span>
                                                <span class="h-6 w-6 rounded-full bg-cyan-500"></span>
                                                <span class="h-6 w-6 rounded-full bg-emerald-500"></span>
                                                <span class="h-6 w-6 rounded-full bg-rose-500"></span>
                                                <span class="h-6 w-6 rounded-full bg-amber-500"></span>
                                            </div>
                                        </div>
                                        <div>
                                            <span class="l-field-label">Assistant face</span>
                                            <div class="mt-1.5 flex items-center gap-2">
                                                <span class="rounded-lg border border-indigo-400 p-1 dark:border-indigo-400/70">
                                                    <x-blobatar :name="'DocuMind'" :size="34" data-blobatar-expression="happy" />
                                                </span>
                                                <span class="rounded-lg border border-transparent p-1">
                                                    <x-blobatar :name="'DocuMind'" :size="34" background="squircle" data-blobatar-expression="wink" />
                                                </span>
                                                <span class="rounded-lg border border-transparent p-1">
                                                    <x-blobatar :name="'DocuMind'" :size="34" data-blobatar-expression="smug" />
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mt-5 flex items-end justify-between rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/[0.03]">
                                        <div class="rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm dark:border-white/10 dark:bg-white/10 dark:text-slate-200">
                                            Need help?
                                        </div>
                                        <span class="relative grid h-12 w-12 place-items-center rounded-full bg-indigo-600 text-white shadow-lg shadow-indigo-600/40">
                                            <x-blobatar :name="'DocuMind'" :size="44" data-blobatar-expression="happy" />
                                        </span>
                                    </div>
                                </div>

                                {{-- Step 4 — embed --}}
                                <div class="l-panel p-6" data-story-panel data-visible="false" aria-hidden="true">
                                    <div class="flex items-center justify-between gap-3">
                                        <p class="text-sm font-bold text-slate-900 dark:text-white">Install snippet</p>
                                        <button type="button" data-copy-snippet class="btn btn-secondary btn-sm">Copy</button>
                                    </div>
                                    <pre class="l-code mt-4 overflow-x-auto text-xs leading-relaxed" aria-label="Widget install snippet"><code>&lt;script src="{{ route('widget.script') }}"
        data-site-key="pk_pxamtz3jcav5ghekinpmqbhq"
        defer&gt;&lt;/script&gt;</code></pre>
                                    <p class="mt-4 text-xs leading-relaxed text-slate-500 dark:text-slate-400">
                                        Paste it before <code class="l-inline-code">&lt;/body&gt;</code> — the widget mounts itself on
                                        load. No build step, no framework required.
                                    </p>
                                </div>

                                {{-- Step 5 — support customers --}}
                                <div class="l-panel p-6" data-story-panel data-visible="false" aria-hidden="true">
                                    <div class="flex items-center justify-between gap-3">
                                        <p class="text-sm font-bold text-slate-900 dark:text-white">Live on your site</p>
                                        <span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[11px] font-bold text-emerald-600 dark:text-emerald-400">Answering now</span>
                                    </div>
                                    <div class="mt-4 flex flex-col gap-2.5">
                                        <div class="l-bubble l-bubble-user self-end">What's your return window?</div>
                                        <div class="l-bubble l-bubble-bot self-start">
                                            90 days, free returns on unopened items — here's the exact wording.
                                            <div class="l-bubble-meta">
                                                <span class="l-src">Refund policy · §1</span>
                                            </div>
                                        </div>
                                        <div class="l-bubble l-bubble-bot self-start flex items-center gap-2">
                                            <span class="l-typing text-indigo-500" aria-hidden="true"><i></i><i></i><i></i></span>
                                        </div>
                                    </div>
                                    <div class="mt-5 grid gap-2.5 sm:grid-cols-2">
                                        <div class="l-mini-stat">
                                            <span class="l-mini-stat-value text-emerald-500">96.4%</span>
                                            <span class="l-mini-stat-label">Answered from knowledge</span>
                                        </div>
                                        <div class="l-mini-stat">
                                            <span class="l-mini-stat-value text-indigo-500">2 min</span>
                                            <span class="l-mini-stat-label">Median handoff reply</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {{-- ── Features ───────────────────────────────────────────── --}}
            <section class="l-section border-y border-slate-200/80 bg-slate-50/60 py-16 dark:border-white/10 dark:bg-white/[0.015] lg:py-24" id="features">
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="reveal mx-auto max-w-2xl text-center">
                        <span class="text-xs font-bold uppercase tracking-[0.18em] text-indigo-500">Features</span>
                        <h2 class="mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl">
                            Everything a support team needs, in one assistant.
                        </h2>
                    </div>

                    <div class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        <article class="l-card reveal">
                            <x-blobatar :name="'DocuMind'" :size="44" data-blobatar-expression="happy" />
                            <h3 class="mt-4 text-base font-bold text-slate-900 dark:text-white">Knowledge sources</h3>
                            <p class="mt-1.5 text-sm leading-relaxed text-slate-600 dark:text-slate-400">Product info, FAQs, policies, terms and docs — PDF, DOCX, Markdown or web pages, chunked, indexed and kept in sync with what you approve.</p>
                        </article>

                        <article class="l-card reveal">
                            <x-blobatar :name="'DocuMind'" :size="44" data-blobatar-expression="smug" />
                            <h3 class="mt-4 text-base font-bold text-slate-900 dark:text-white">Grounded answers</h3>
                            <p class="mt-1.5 text-sm leading-relaxed text-slate-600 dark:text-slate-400">Responses cite the source page. When it isn't in the docs, the assistant says so instead of guessing.</p>
                        </article>

                        <article class="l-card reveal">
                            <x-blobatar :name="'DocuMind'" :size="44" data-blobatar-expression="wink" />
                            <h3 class="mt-4 text-base font-bold text-slate-900 dark:text-white">Human handoff</h3>
                            <p class="mt-1.5 text-sm leading-relaxed text-slate-600 dark:text-slate-400">Escalate with the full transcript so your team replies with context — no "let me repeat everything".</p>
                        </article>

                        <article class="l-card reveal">
                            <x-blobatar :name="'DocuMind'" :size="44" data-blobatar-expression="love" />
                            <h3 class="mt-4 text-base font-bold text-slate-900 dark:text-white">Visitor email capture</h3>
                            <p class="mt-1.5 text-sm leading-relaxed text-slate-600 dark:text-slate-400">Consent-gated lead collection that turns curious visitors into contacts you can follow up with.</p>
                        </article>

                        <article class="l-card reveal">
                            <x-blobatar :name="'DocuMind'" :size="44" data-blobatar-expression="thinking" />
                            <h3 class="mt-4 text-base font-bold text-slate-900 dark:text-white">Conversation history</h3>
                            <p class="mt-1.5 text-sm leading-relaxed text-slate-600 dark:text-slate-400">Searchable transcripts with visitor feedback — see exactly what was asked, answered and upvoted.</p>
                        </article>

                        <article class="l-card reveal">
                            <x-blobatar :name="'DocuMind'" :size="44" data-blobatar-expression="surprised" />
                            <h3 class="mt-4 text-base font-bold text-slate-900 dark:text-white">Analytics &amp; export</h3>
                            <p class="mt-1.5 text-sm leading-relaxed text-slate-600 dark:text-slate-400">Success, failure and unanswered questions over time — filter, inspect and export to CSV.</p>
                        </article>

                        <article class="l-card reveal">
                            <x-blobatar :name="'DocuMind'" :size="44" data-blobatar-expression="idle" />
                            <h3 class="mt-4 text-base font-bold text-slate-900 dark:text-white">Multi-user workspaces</h3>
                            <p class="mt-1.5 text-sm leading-relaxed text-slate-600 dark:text-slate-400">Invite your team with roles and permissions — shared sites, shared history, one source of truth.</p>
                        </article>

                        <article class="l-card l-card-cta reveal">
                            <div class="grid h-11 w-11 place-items-center rounded-xl bg-indigo-500/10 text-indigo-500" aria-hidden="true">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12l-7.5 7.5M3 12h17" /></svg>
                            </div>
                            <h3 class="mt-4 text-base font-bold text-slate-900 dark:text-white">Ready in five minutes?</h3>
                            <p class="mt-1.5 text-sm leading-relaxed text-slate-600 dark:text-slate-400">Upload one document and see your assistant answer before your coffee cools.</p>
                            <a href="{{ route('login') }}" class="btn btn-primary btn-sm mt-4 self-start">Create your support bot</a>
                        </article>
                    </div>
                </div>
            </section>

            {{-- ── Live widget demo ───────────────────────────────────── --}}
            <section class="l-section relative overflow-hidden py-16 lg:py-24" id="demo">
                <div class="l-glow bottom-0 left-[-8rem] h-[26rem] w-[26rem] bg-violet-600/15" aria-hidden="true"></div>

                <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="reveal mx-auto max-w-2xl text-center">
                        <span class="text-xs font-bold uppercase tracking-[0.18em] text-indigo-500">Live demo</span>
                        <h2 class="mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl">
                            Meet your new teammate.
                        </h2>
                        <p class="mt-4 text-base leading-relaxed text-slate-600 dark:text-slate-400">
                            This is the actual widget your visitors get — click the launcher, ask a question,
                            or hand the conversation to a human.
                        </p>
                    </div>

                    <div class="reveal l-viewport mx-auto mt-10 max-w-3xl">
                        <div class="flex items-center gap-2 px-3 py-2.5 sm:px-4">
                            <span class="h-2.5 w-2.5 rounded-full bg-rose-400" aria-hidden="true"></span>
                            <span class="h-2.5 w-2.5 rounded-full bg-amber-400" aria-hidden="true"></span>
                            <span class="h-2.5 w-2.5 rounded-full bg-emerald-400" aria-hidden="true"></span>
                            <span class="ml-2 hidden truncate rounded-md bg-slate-100 px-3 py-1 text-xs font-medium text-slate-500 sm:block dark:bg-white/5 dark:text-slate-400">
                                yoursite.com/pricing
                            </span>
                            <button type="button" data-demo-replay class="btn btn-ghost btn-sm ml-auto">Replay demo</button>
                        </div>

                        <div class="l-widget-site relative m-2 mt-0 overflow-hidden rounded-b-xl sm:m-3 sm:mt-0" data-widget-demo data-open="false">
                            {{-- Faux host page: a small pricing site, so the widget sits on something real --}}
                            <div class="l-faux-page" aria-hidden="true">
                                <div class="l-faux-nav">
                                    <span class="flex items-center gap-1.5">
                                        <span class="h-5 w-5 rounded-md bg-gradient-to-br from-indigo-500 to-violet-500" aria-hidden="true"></span>
                                        <span class="text-[11px] font-extrabold tracking-tight text-slate-800 dark:text-slate-100">acme</span>
                                    </span>
                                    <span class="hidden items-center gap-4 sm:flex">
                                        <span class="text-[10px] font-medium text-slate-500 dark:text-slate-400">Product</span>
                                        <span class="text-[10px] font-medium text-slate-500 dark:text-slate-400">Docs</span>
                                        <span class="text-[10px] font-bold text-indigo-600 dark:text-indigo-400">Pricing</span>
                                    </span>
                                    <span class="rounded-md bg-indigo-500 px-2 py-1 text-[9px] font-bold uppercase tracking-wide text-white shadow-sm shadow-indigo-500/40">Get started</span>
                                </div>

                                <div class="mt-5 text-center">
                                    <span class="text-[9px] font-bold uppercase tracking-[0.22em] text-indigo-500">Pricing</span>
                                    <p class="mt-1 text-base font-extrabold tracking-tight text-slate-900 dark:text-white sm:text-lg">Plans that scale with you</p>
                                    <p class="mt-0.5 text-[10px] font-medium text-slate-500 dark:text-slate-400">Start free — upgrade when your support volume grows.</p>
                                </div>

                                <div class="mt-4 grid grid-cols-3 gap-2.5 sm:gap-3">
                                    <div class="l-faux-plan">
                                        <p class="l-faux-plan-name">Starter</p>
                                        <p class="l-faux-plan-price">$0<span>/mo</span></p>
                                        <span class="l-faux-line"></span>
                                        <span class="l-faux-line l-faux-line-short"></span>
                                        <span class="l-faux-plan-btn">Choose</span>
                                    </div>

                                    <div class="l-faux-plan l-faux-plan-featured">
                                        <span class="l-faux-plan-tag">Popular</span>
                                        <p class="l-faux-plan-name">Pro</p>
                                        <p class="l-faux-plan-price">$49<span>/mo</span></p>
                                        <span class="l-faux-line"></span>
                                        <span class="l-faux-line l-faux-line-short"></span>
                                        <span class="l-faux-plan-btn l-faux-plan-btn-primary">Choose</span>
                                    </div>

                                    <div class="l-faux-plan">
                                        <p class="l-faux-plan-name">Scale</p>
                                        <p class="l-faux-plan-price">$199<span>/mo</span></p>
                                        <span class="l-faux-line"></span>
                                        <span class="l-faux-line l-faux-line-short"></span>
                                        <span class="l-faux-plan-btn">Choose</span>
                                    </div>
                                </div>

                                <p class="mt-4 text-center text-[9px] font-medium tracking-wide text-slate-400 dark:text-slate-500">
                                    No credit card required · Cancel anytime
                                </p>
                            </div>

                            {{-- Hint pill --}}
                            <span class="l-widget-hint" data-demo-hint aria-hidden="true">Need help?</span>

                            {{-- Launcher --}}
                            <button
                                type="button"
                                data-demo-launcher
                                class="l-widget-launcher"
                                aria-expanded="false"
                                aria-controls="demo-widget-panel"
                                aria-label="Open the support chat demo"
                            >
                                <span class="l-launcher-chat" aria-hidden="true">
                                    <x-blobatar :name="'DocuMind'" :size="44" data-blobatar-expression="happy" data-blobatar-animate="live" />
                                </span>
                                <svg class="l-launcher-close h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                                    <path stroke-linecap="round" d="M6 6l12 12M18 6 6 18" />
                                </svg>
                            </button>

                            {{-- Panel --}}
                            <div class="l-widget-panel" id="demo-widget-panel" role="dialog" aria-label="Support chat demo">
                                <div class="flex items-center gap-2.5 border-b border-slate-200/80 px-3.5 py-3 dark:border-white/10">
                                    <span class="relative">
                                        <x-blobatar :name="'DocuMind'" :size="36" data-blobatar-expression="happy" data-blobatar-animate="live" />
                                        <span class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full bg-emerald-500 ring-2 ring-white dark:ring-[#12141c]" aria-hidden="true"></span>
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-[13px] font-bold text-slate-900 dark:text-white">Sonic Support</p>
                                        <p class="text-[11px] font-medium text-slate-500 dark:text-slate-400">Online · replies instantly</p>
                                    </div>
                                    <span class="rounded-full bg-indigo-500/10 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-indigo-500">Demo</span>
                                </div>

                                <div class="flex-1 space-y-3 overflow-y-auto px-3.5 py-3" data-demo-log role="log" aria-live="polite">
                                    <div class="l-bubble l-bubble-bot">
                                        Hi! I'm your support assistant, trained on the team's approved docs. Ask me anything — or hand off to a human anytime.
                                    </div>

                                    <div class="flex flex-wrap gap-1.5 pt-0.5">
                                        <button type="button" class="l-chip l-chip-sm" data-demo-ask="pro">What's included in Pro?</button>
                                        <button type="button" class="l-chip l-chip-sm" data-demo-ask="refund">What's your refund window?</button>
                                        <button type="button" class="l-chip l-chip-sm" data-demo-ask="human">Talk to a human</button>
                                    </div>
                                </div>

                                <form class="flex items-center gap-2 border-t border-slate-200/80 p-2.5 dark:border-white/10" data-demo-form>
                                    <label class="sr-only" for="demo-widget-input">Ask the demo assistant a question</label>
                                    <input
                                        id="demo-widget-input"
                                        name="question"
                                        type="text"
                                        autocomplete="off"
                                        placeholder="Ask a question…"
                                        class="min-w-0 flex-1 rounded-lg border border-slate-200 bg-white px-3 py-2 text-[13px] text-slate-900 placeholder:text-slate-400 focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-white/10 dark:bg-white/5 dark:text-white dark:placeholder:text-slate-500"
                                    >
                                    <button type="submit" class="btn btn-primary btn-icon !min-h-0 !h-8 !w-8 !rounded-lg !p-0" aria-label="Send message">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12h15m0 0-6-6m6 6-6 6" />
                                        </svg>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {{-- ── Use cases ──────────────────────────────────────────── --}}
            <section class="l-section border-y border-slate-200/80 bg-slate-50/60 py-16 dark:border-white/10 dark:bg-white/[0.015] lg:py-24" id="use-cases">
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="reveal mx-auto max-w-2xl text-center">
                        <span class="text-xs font-bold uppercase tracking-[0.18em] text-indigo-500">Use cases</span>
                        <h2 class="mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl">
                            One assistant, every corner of your site.
                        </h2>
                    </div>

                    <div class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <article class="l-use reveal">
                            <span class="l-use-icon" aria-hidden="true">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 9.5h8M8 13h5m11-1a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                            </span>
                            <h3 class="mt-3 text-base font-bold text-slate-900 dark:text-white">SaaS support</h3>
                            <p class="mt-1 text-sm leading-relaxed text-slate-600 dark:text-slate-400">Deflect repetitive "how do I…" tickets with instant, docs-accurate answers inside your product site.</p>
                        </article>

                        <article class="l-use reveal">
                            <span class="l-use-icon" aria-hidden="true">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="m3 11 18-8-8 18-2.5-7.5L3 11Z" /></svg>
                            </span>
                            <h3 class="mt-3 text-base font-bold text-slate-900 dark:text-white">Customer onboarding</h3>
                            <p class="mt-1 text-sm leading-relaxed text-slate-600 dark:text-slate-400">Guide new signups through setup steps, billing and first value — without waiting on a human.</p>
                        </article>

                        <article class="l-use reveal">
                            <span class="l-use-icon" aria-hidden="true">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z" /></svg>
                            </span>
                            <h3 class="mt-3 text-base font-bold text-slate-900 dark:text-white">Documentation help</h3>
                            <p class="mt-1 text-sm leading-relaxed text-slate-600 dark:text-slate-400">Turn your docs site into a conversation — the assistant jumps readers to the right section.</p>
                        </article>

                        <article class="l-use reveal">
                            <span class="l-use-icon" aria-hidden="true">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                            </span>
                            <h3 class="mt-3 text-base font-bold text-slate-900 dark:text-white">Terms &amp; policies</h3>
                            <p class="mt-1 text-sm leading-relaxed text-slate-600 dark:text-slate-400">Explain refunds, retention and privacy in plain language — always quoting your approved wording.</p>
                        </article>

                        <article class="l-use reveal">
                            <span class="l-use-icon" aria-hidden="true">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7 12 3 4 7m16 0-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" /></svg>
                            </span>
                            <h3 class="mt-3 text-base font-bold text-slate-900 dark:text-white">Product help</h3>
                            <p class="mt-1 text-sm leading-relaxed text-slate-600 dark:text-slate-400">Answer "does it support X?" questions on your marketing pages, with sources a buyer can verify.</p>
                        </article>

                        <article class="l-use reveal">
                            <span class="l-use-icon" aria-hidden="true">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5h4.5v6h-4.5v-6Zm6-9v15h4.5v-15h-4.5Zm6 4.5V19.5H21v-10.5a1.5 1.5 0 0 0-1.5-1.5h-1.5Z" /></svg>
                            </span>
                            <h3 class="mt-3 text-base font-bold text-slate-900 dark:text-white">Customer service</h3>
                            <p class="mt-1 text-sm leading-relaxed text-slate-600 dark:text-slate-400">Front-line triage that resolves the easy 70% and hands the rest to your team with full context.</p>
                        </article>
                    </div>
                </div>
            </section>

            {{-- ── Platform tour (interactive) ───────────────────────── --}}
            <section class="l-section relative overflow-hidden py-16 lg:py-24" id="platform" data-platform>
                <div class="l-glow right-[-6rem] top-8 h-[22rem] w-[22rem] bg-cyan-500/15" aria-hidden="true"></div>

                <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="reveal mx-auto max-w-2xl text-center">
                        <span class="text-xs font-bold uppercase tracking-[0.18em] text-indigo-500">Platform</span>
                        <h2 class="mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl">
                            Everything behind the widget.
                        </h2>
                        <p class="mt-4 text-base leading-relaxed text-slate-600 dark:text-slate-400">
                            Customize the experience, track what's working, run it as a team, stay in control —
                            and connect the tools you already use. Pick a tab to explore.
                        </p>
                    </div>

                    <div class="reveal l-tabs mt-10" role="tablist" aria-label="Platform areas">
                        <button type="button" role="tab" id="tab-customize" aria-controls="panel-customize" aria-selected="true" tabindex="0" class="l-tab">Widget customization</button>
                        <button type="button" role="tab" id="tab-analytics" aria-controls="panel-analytics" aria-selected="false" tabindex="-1" class="l-tab">Analytics</button>
                        <button type="button" role="tab" id="tab-workspaces" aria-controls="panel-workspaces" aria-selected="false" tabindex="-1" class="l-tab">Workspaces</button>
                        <button type="button" role="tab" id="tab-admin" aria-controls="panel-admin" aria-selected="false" tabindex="-1" class="l-tab">Admin controls</button>
                        <button type="button" role="tab" id="tab-integrations" aria-controls="panel-integrations" aria-selected="false" tabindex="-1" class="l-tab">Integrations</button>
                    </div>

                    {{-- Tab: widget customization (live preview) --}}
                    <div class="l-platform-panel reveal" role="tabpanel" id="panel-customize" aria-labelledby="tab-customize" data-platform-panel>
                        <div class="l-platform-grid">
                            <div>
                                <p class="text-sm font-bold text-slate-900 dark:text-white">Accent color</p>
                                <div class="mt-3 flex flex-wrap gap-3" role="group" aria-label="Widget accent color">
                                    <button type="button" class="l-swatch" data-accent data-accent-hex="#6366f1" aria-pressed="true" aria-label="Indigo accent" style="--sw:#6366f1"></button>
                                    <button type="button" class="l-swatch" data-accent data-accent-hex="#06b6d4" aria-pressed="false" aria-label="Cyan accent" style="--sw:#06b6d4"></button>
                                    <button type="button" class="l-swatch" data-accent data-accent-hex="#10b981" aria-pressed="false" aria-label="Emerald accent" style="--sw:#10b981"></button>
                                    <button type="button" class="l-swatch" data-accent data-accent-hex="#f43f5e" aria-pressed="false" aria-label="Rose accent" style="--sw:#f43f5e"></button>
                                    <button type="button" class="l-swatch" data-accent data-accent-hex="#f59e0b" aria-pressed="false" aria-label="Amber accent" style="--sw:#f59e0b"></button>
                                </div>

                                <p class="mt-7 text-sm font-bold text-slate-900 dark:text-white">Assistant face</p>
                                <div class="mt-3 flex flex-wrap gap-2" role="group" aria-label="Assistant face">
                                    <button type="button" class="l-face-btn" data-face="happy" aria-pressed="true" aria-label="Happy face">
                                        <x-blobatar :name="'DocuMind'" :size="38" data-blobatar-expression="happy" data-blobatar-animate="live" />
                                    </button>
                                    <button type="button" class="l-face-btn" data-face="wink" aria-pressed="false" aria-label="Wink face">
                                        <x-blobatar :name="'DocuMind'" :size="38" data-blobatar-expression="wink" data-blobatar-animate="live" />
                                    </button>
                                    <button type="button" class="l-face-btn" data-face="smug" aria-pressed="false" aria-label="Smug face">
                                        <x-blobatar :name="'DocuMind'" :size="38" data-blobatar-expression="smug" data-blobatar-animate="live" />
                                    </button>
                                    <button type="button" class="l-face-btn" data-face="thinking" aria-pressed="false" aria-label="Thinking face">
                                        <x-blobatar :name="'DocuMind'" :size="38" data-blobatar-expression="thinking" data-blobatar-animate="live" />
                                    </button>
                                </div>

                                <p class="mt-7 text-sm font-bold text-slate-900 dark:text-white">Greeting</p>
                                <p class="l-field-value mt-2">"Hi! I know our docs inside out — what can I help you find?"</p>

                                <p class="mt-5 text-xs leading-relaxed text-slate-500 dark:text-slate-400">
                                    Try the swatches and faces — the preview updates instantly, exactly like your dashboard does.
                                </p>
                            </div>

                            <div class="l-custom-preview" data-custom-preview style="--l-widget-accent:#6366f1" aria-label="Live widget preview">
                                <div class="l-custom-head">
                                    <span class="relative">
                                        <x-blobatar :name="'DocuMind'" :size="34" data-custom-avatar data-blobatar-expression="happy" data-blobatar-animate="live" />
                                        <span class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full bg-emerald-500 ring-2 ring-white dark:ring-[#12141c]" aria-hidden="true"></span>
                                    </span>
                                    <div class="min-w-0">
                                        <p class="truncate text-[13px] font-bold text-slate-900 dark:text-white">Sonic Support</p>
                                        <p class="text-[11px] font-medium text-slate-500 dark:text-slate-400">Online · answering from your sources</p>
                                    </div>
                                </div>
                                <div class="l-custom-body">
                                    <div class="l-bubble l-bubble-bot self-start">Hi! I know our docs inside out — what can I help you find?</div>
                                    <div class="l-bubble l-bubble-user self-end">What's included in Pro?</div>
                                    <div class="l-bubble l-bubble-bot self-start">
                                        3 sites, 2,000 messages a month, unlimited documents and analytics export.
                                        <div class="l-bubble-meta"><span class="l-src">Pricing page</span></div>
                                    </div>
                                </div>
                                <div class="l-custom-foot">
                                    <span class="l-custom-input">Ask a question…</span>
                                    <span class="l-custom-send" aria-hidden="true">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12h15m0 0-6-6m6 6-6 6" /></svg>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Tab: analytics --}}
                    <div class="l-platform-panel reveal" role="tabpanel" id="panel-analytics" aria-labelledby="tab-analytics" data-platform-panel hidden>
                        <div class="l-platform-grid">
                            <div>
                                <p class="text-sm font-bold text-slate-900 dark:text-white">Every conversation, accounted for.</p>
                                <p class="mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-400">
                                    Successful answers, failed ones, unanswered questions and captured leads —
                                    in real time, exportable to CSV, and shared with everyone who needs them.
                                </p>
                                <ul class="mt-5 space-y-2.5 text-sm font-medium text-slate-700 dark:text-slate-300">
                                    <li class="flex items-center gap-2.5"><span class="h-1.5 w-1.5 rounded-full bg-indigo-500" aria-hidden="true"></span>Trend your success rate week over week</li>
                                    <li class="flex items-center gap-2.5"><span class="h-1.5 w-1.5 rounded-full bg-indigo-500" aria-hidden="true"></span>Spot knowledge gaps the moment they appear</li>
                                    <li class="flex items-center gap-2.5"><span class="h-1.5 w-1.5 rounded-full bg-indigo-500" aria-hidden="true"></span>Review leads and escalations in one feed</li>
                                </ul>
                            </div>

                            <div class="l-panel overflow-hidden">
                                <div class="flex items-center justify-between gap-3 border-b border-slate-200/80 px-5 py-3.5 dark:border-white/10">
                                    <p class="text-sm font-bold text-slate-900 dark:text-white">Overview</p>
                                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-500 dark:bg-white/5 dark:text-slate-400">Last 7 days</span>
                                </div>

                                <div class="grid grid-cols-2 gap-3 p-5 sm:grid-cols-4">
                                    <div class="l-stat l-stat-tile"><span class="l-stat-value text-emerald-500">1,284</span><span class="l-stat-label">Successful responses</span></div>
                                    <div class="l-stat l-stat-tile"><span class="l-stat-value text-rose-500">21</span><span class="l-stat-label">Failed responses</span></div>
                                    <div class="l-stat l-stat-tile"><span class="l-stat-value text-amber-500">14</span><span class="l-stat-label">Unanswered questions</span></div>
                                    <div class="l-stat l-stat-tile"><span class="l-stat-value text-indigo-500">87</span><span class="l-stat-label">Visitor leads</span></div>
                                </div>

                                <div class="px-5 pb-5">
                                    <div class="flex h-28 items-end gap-1.5 rounded-xl border border-slate-200/70 bg-slate-50 px-3 pt-3 dark:border-white/10 dark:bg-white/[0.03]" aria-hidden="true">
                                        <span class="l-bar h-[34%]"></span><span class="l-bar h-[52%]"></span><span class="l-bar h-[45%]"></span>
                                        <span class="l-bar h-[68%]"></span><span class="l-bar h-[58%]"></span><span class="l-bar h-[82%]"></span>
                                        <span class="l-bar h-[74%]"></span><span class="l-bar h-[91%]"></span><span class="l-bar h-[66%]"></span>
                                        <span class="l-bar h-[88%]"></span><span class="l-bar h-full"></span><span class="l-bar h-[79%]"></span>
                                    </div>
                                </div>

                                <ul class="divide-y divide-slate-200/80 border-t border-slate-200/80 text-sm dark:divide-white/10 dark:border-white/10">
                                    <li class="flex items-center gap-3 px-5 py-2.5">
                                        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-emerald-500" aria-hidden="true"></span>
                                        <span class="min-w-0 flex-1 truncate text-slate-700 dark:text-slate-300">Visitor on <span class="font-semibold">/pricing</span> asked about refunds — answered with Terms §4</span>
                                        <span class="shrink-0 text-xs text-slate-400">2m</span>
                                    </li>
                                    <li class="flex items-center gap-3 px-5 py-2.5">
                                        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-indigo-500" aria-hidden="true"></span>
                                        <span class="min-w-0 flex-1 truncate text-slate-700 dark:text-slate-300">Lead captured — <span class="font-semibold">sam@acme.io</span> (consented)</span>
                                        <span class="shrink-0 text-xs text-slate-400">14m</span>
                                    </li>
                                    <li class="flex items-center gap-3 px-5 py-2.5">
                                        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500" aria-hidden="true"></span>
                                        <span class="min-w-0 flex-1 truncate text-slate-700 dark:text-slate-300">Escalated to a human — full transcript attached</span>
                                        <span class="shrink-0 text-xs text-slate-400">1h</span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    {{-- Tab: workspaces --}}
                    <div class="l-platform-panel reveal" role="tabpanel" id="panel-workspaces" aria-labelledby="tab-workspaces" data-platform-panel hidden>
                        <div class="l-platform-grid">
                            <div>
                                <p class="text-sm font-bold text-slate-900 dark:text-white">One shared view of every conversation.</p>
                                <p class="mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-400">
                                    Invite teammates with roles and permissions — shared sites, shared history,
                                    one source of truth. Roles are enforced everywhere: members read
                                    conversations, admins edit knowledge and settings, only owners manage billing.
                                </p>
                                <div class="mt-5 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 dark:border-indigo-500/25 dark:bg-indigo-500/10">
                                    <p class="text-xs font-semibold leading-relaxed text-indigo-700 dark:text-indigo-300">
                                        Unlimited invitations on Scale · Google sign-in keeps everyone in the right workspace
                                    </p>
                                </div>
                            </div>

                            <div class="l-panel overflow-hidden">
                                <div class="flex items-center justify-between gap-3 border-b border-slate-200/80 px-5 py-3.5 dark:border-white/10">
                                    <p class="text-sm font-bold text-slate-900 dark:text-white">Team workspace</p>
                                    <span class="rounded-full bg-indigo-500/10 px-2.5 py-1 text-[11px] font-bold text-indigo-500">3 members</span>
                                </div>
                                <ul class="divide-y divide-slate-200/80 dark:divide-white/10">
                                    <li class="l-kb-row">
                                        <x-blobatar :name="'Sana'" :size="30" />
                                        <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-800 dark:text-slate-200">Sana Iyer <span class="font-normal text-slate-400">· sana@acme.io</span></span>
                                        <span class="text-xs font-semibold text-indigo-500">Owner</span>
                                    </li>
                                    <li class="l-kb-row">
                                        <x-blobatar :name="'Ravi'" :size="30" />
                                        <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-800 dark:text-slate-200">Ravi Mehta <span class="font-normal text-slate-400">· ravi@acme.io</span></span>
                                        <span class="text-xs font-semibold text-cyan-600 dark:text-cyan-400">Admin</span>
                                    </li>
                                    <li class="l-kb-row">
                                        <x-blobatar :name="'Mira'" :size="30" />
                                        <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-800 dark:text-slate-200">Mira Gómez <span class="font-normal text-slate-400">· mira@acme.io</span></span>
                                        <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Member</span>
                                    </li>
                                    <li class="l-kb-row l-kb-row-invite">
                                        <span class="l-file-icon" aria-hidden="true">+</span>
                                        <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-500 dark:text-slate-400">Invite a teammate by email…</span>
                                        <span class="text-xs font-semibold text-indigo-500">Send invite</span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    {{-- Tab: admin controls --}}
                    <div class="l-platform-panel reveal" role="tabpanel" id="panel-admin" aria-labelledby="tab-admin" data-platform-panel hidden>
                        <div class="l-platform-grid">
                            <div>
                                <p class="text-sm font-bold text-slate-900 dark:text-white">You hold the keys.</p>
                                <p class="mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-400">
                                    Rotate site keys, suspend the widget, set retention windows, and export or
                                    delete everything from one screen. Every action is logged to the workspace.
                                </p>
                                <ul class="mt-5 space-y-2.5 text-sm font-medium text-slate-700 dark:text-slate-300">
                                    <li class="flex items-center gap-2.5"><span class="h-1.5 w-1.5 rounded-full bg-indigo-500" aria-hidden="true"></span>One-click CSV export of every conversation</li>
                                    <li class="flex items-center gap-2.5"><span class="h-1.5 w-1.5 rounded-full bg-indigo-500" aria-hidden="true"></span>Full workspace deletion with typed confirmation</li>
                                    <li class="flex items-center gap-2.5"><span class="h-1.5 w-1.5 rounded-full bg-indigo-500" aria-hidden="true"></span>Retention windows from 30 days to keep-forever</li>
                                </ul>
                            </div>

                            <div class="l-panel overflow-hidden">
                                <div class="flex items-center justify-between gap-3 border-b border-slate-200/80 px-5 py-3.5 dark:border-white/10">
                                    <p class="text-sm font-bold text-slate-900 dark:text-white">Workspace controls</p>
                                    <span class="rounded-full bg-emerald-500/10 px-2.5 py-1 text-[11px] font-bold text-emerald-600 dark:text-emerald-400">Healthy</span>
                                </div>
                                <ul class="divide-y divide-slate-200/80 text-sm dark:divide-white/10">
                                    <li class="flex items-center gap-3 px-5 py-3.5">
                                        <span class="min-w-0 flex-1">
                                            <span class="block font-semibold text-slate-800 dark:text-slate-200">Public site key</span>
                                            <span class="l-inline-code">pk_pxa…mqbh</span>
                                        </span>
                                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-bold text-slate-500 dark:bg-white/5 dark:text-slate-400">Rotate anytime</span>
                                    </li>
                                    <li class="flex items-center gap-3 px-5 py-3.5">
                                        <span class="min-w-0 flex-1 font-semibold text-slate-800 dark:text-slate-200">Widget live on all sites</span>
                                        <button type="button" role="switch" aria-checked="true" data-admin-switch class="l-switch" aria-label="Widget live on all sites"></button>
                                    </li>
                                    <li class="flex items-center gap-3 px-5 py-3.5">
                                        <span class="min-w-0 flex-1 font-semibold text-slate-800 dark:text-slate-200">Weekly analytics CSV by email</span>
                                        <button type="button" role="switch" aria-checked="false" data-admin-switch class="l-switch" aria-label="Weekly analytics CSV by email"></button>
                                    </li>
                                    <li class="flex items-center gap-3 px-5 py-3.5">
                                        <span class="min-w-0 flex-1 font-semibold text-slate-800 dark:text-slate-200">Data retention</span>
                                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-bold text-slate-500 dark:bg-white/5 dark:text-slate-400">90 days</span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    {{-- Tab: integrations --}}
                    <div class="l-platform-panel reveal" role="tabpanel" id="panel-integrations" aria-labelledby="tab-integrations" data-platform-panel hidden>
                        <div class="l-platform-grid">
                            <div>
                                <p class="text-sm font-bold text-slate-900 dark:text-white">Install anywhere, connect the rest.</p>
                                <p class="mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-400">
                                    One script tag works with every framework and site builder. Sign-in comes via
                                    Google, leads land where your team already works, and analytics leave as CSV
                                    whenever you need them elsewhere.
                                </p>
                                <ul class="mt-5 space-y-2.5 text-sm font-medium text-slate-700 dark:text-slate-300">
                                    <li class="flex items-center gap-2.5"><span class="h-1.5 w-1.5 rounded-full bg-indigo-500" aria-hidden="true"></span>Google sign-in, matched to your verified email</li>
                                    <li class="flex items-center gap-2.5"><span class="h-1.5 w-1.5 rounded-full bg-indigo-500" aria-hidden="true"></span>Consented lead emails for follow-ups</li>
                                    <li class="flex items-center gap-2.5"><span class="h-1.5 w-1.5 rounded-full bg-indigo-500" aria-hidden="true"></span>CSV export for BI, spreadsheets and warehouses</li>
                                </ul>
                            </div>

                            <div class="l-panel p-5">
                                <p class="text-sm font-bold text-slate-900 dark:text-white">Works out of the box with</p>
                                <div class="mt-4 flex flex-wrap gap-2.5">
                                    <span class="l-int">React</span>
                                    <span class="l-int">Next.js</span>
                                    <span class="l-int">Vue</span>
                                    <span class="l-int">Svelte</span>
                                    <span class="l-int">Laravel</span>
                                    <span class="l-int">WordPress</span>
                                    <span class="l-int">Webflow</span>
                                    <span class="l-int">Shopify</span>
                                    <span class="l-int">Plain HTML</span>
                                </div>
                                <div class="mt-5 flex items-center gap-3 rounded-xl border border-indigo-200 bg-indigo-50 px-3 py-2.5 dark:border-indigo-500/25 dark:bg-indigo-500/10">
                                    <x-blobatar :name="'DocuMind'" :size="30" data-blobatar-expression="wink" data-blobatar-animate="live" />
                                    <p class="text-xs font-medium text-indigo-700 dark:text-indigo-300">Paste one script tag before &lt;/body&gt; — no build step, no framework lock-in.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {{-- ── Trust ──────────────────────────────────────────────── --}}
            <section class="l-section border-y border-slate-200/80 bg-slate-50/60 py-16 dark:border-white/10 dark:bg-white/[0.015] lg:py-24" id="trust">
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="reveal mx-auto max-w-2xl text-center">
                        <span class="text-xs font-bold uppercase tracking-[0.18em] text-indigo-500">Security &amp; privacy</span>
                        <h2 class="mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl">
                            Built for teams that take data seriously.
                        </h2>
                    </div>

                    <div class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="l-trust reveal">
                            <span class="l-trust-icon" aria-hidden="true"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3 4.5 6v5.25c0 4.5 3.2 8.7 7.5 9.75 4.3-1.05 7.5-5.25 7.5-9.75V6L12 3Z" /></svg></span>
                            <h3 class="text-sm font-bold text-slate-900 dark:text-white">Privacy by default</h3>
                            <p class="mt-1 text-xs leading-relaxed text-slate-600 dark:text-slate-400">Only the data you choose to keep — nothing sold, nothing shared with model trainers.</p>
                        </div>

                        <div class="l-trust reveal">
                            <span class="l-trust-icon" aria-hidden="true"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg></span>
                            <h3 class="text-sm font-bold text-slate-900 dark:text-white">Consent controls</h3>
                            <p class="mt-1 text-xs leading-relaxed text-slate-600 dark:text-slate-400">Visitors opt in before an email is captured — consent is explicit, logged and revocable.</p>
                        </div>

                        <div class="l-trust reveal">
                            <span class="l-trust-icon" aria-hidden="true"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg></span>
                            <h3 class="text-sm font-bold text-slate-900 dark:text-white">Data retention windows</h3>
                            <p class="mt-1 text-xs leading-relaxed text-slate-600 dark:text-slate-400">Choose how long conversations live — from 30 days to keep-forever, then it's purged.</p>
                        </div>

                        <div class="l-trust reveal">
                            <span class="l-trust-icon" aria-hidden="true"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg></span>
                            <h3 class="text-sm font-bold text-slate-900 dark:text-white">Tenant isolation</h3>
                            <p class="mt-1 text-xs leading-relaxed text-slate-600 dark:text-slate-400">Every workspace's documents and chats are sealed — no cross-tenant leakage, ever.</p>
                        </div>

                        <div class="l-trust reveal">
                            <span class="l-trust-icon" aria-hidden="true"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg></span>
                            <h3 class="text-sm font-bold text-slate-900 dark:text-white">Role-based access</h3>
                            <p class="mt-1 text-xs leading-relaxed text-slate-600 dark:text-slate-400">Owner, admin and member roles decide who can read, edit, export or bill.</p>
                        </div>

                        <div class="l-trust reveal">
                            <span class="l-trust-icon" aria-hidden="true"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg></span>
                            <h3 class="text-sm font-bold text-slate-900 dark:text-white">Encrypted end to end</h3>
                            <p class="mt-1 text-xs leading-relaxed text-slate-600 dark:text-slate-400">TLS in transit, encrypted storage at rest — credentials never exposed to the browser.</p>
                        </div>

                        <div class="l-trust reveal">
                            <span class="l-trust-icon" aria-hidden="true"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75" /></svg></span>
                            <h3 class="text-sm font-bold text-slate-900 dark:text-white">Administrator controls</h3>
                            <p class="mt-1 text-xs leading-relaxed text-slate-600 dark:text-slate-400">Rotate site keys, suspend the widget, or export/delete everything from one screen.</p>
                        </div>

                        <div class="l-trust l-trust-cta reveal">
                            <span class="l-trust-icon" aria-hidden="true"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg></span>
                            <h3 class="text-sm font-bold text-slate-900 dark:text-white">Read the fine print</h3>
                            <p class="mt-1 text-xs leading-relaxed text-slate-600 dark:text-slate-400">The full story on what we store, why, and the controls you have over it.</p>
                            <a href="{{ route('privacy.policy') }}" class="mt-3 inline-flex items-center gap-1.5 text-xs font-bold text-indigo-500 hover:underline">
                                Privacy policy
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12l-7.5 7.5M3 12h17" /></svg>
                            </a>
                        </div>
                    </div>
                </div>
            </section>

            {{-- ── Pricing ────────────────────────────────────────────── --}}
            <section class="l-section relative overflow-hidden py-16 lg:py-24" id="pricing">
                <div class="l-glow left-1/2 top-4 h-[22rem] w-[36rem] -translate-x-1/2 bg-indigo-600/12" aria-hidden="true"></div>

                <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="reveal mx-auto max-w-2xl text-center">
                        <span class="text-xs font-bold uppercase tracking-[0.18em] text-indigo-500">Pricing</span>
                        <h2 class="mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl">Simple plans, clear limits.</h2>
                        <p class="mt-4 text-base text-slate-600 dark:text-slate-400">Billed monthly. Upgrade, downgrade or cancel anytime.</p>
                    </div>

                    <div class="mt-12 grid gap-5 lg:grid-cols-3">
                        <div class="l-price reveal">
                            <p class="text-sm font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">Starter</p>
                            <p class="mt-3 flex items-baseline gap-1">
                                <span class="text-4xl font-extrabold tracking-tight text-slate-900 dark:text-white">$0</span>
                                <span class="text-sm font-medium text-slate-500 dark:text-slate-400">/ month</span>
                            </p>
                            <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">Try it on one site, no card required.</p>
                            <ul class="mt-5 space-y-2.5 text-sm text-slate-700 dark:text-slate-300">
                                <li class="l-tick">1 site · 100 messages / month</li>
                                <li class="l-tick">3 knowledge documents</li>
                                <li class="l-tick">Widget customization &amp; Blobatar</li>
                                <li class="l-tick">Conversation history (30 days)</li>
                            </ul>
                            <a href="{{ route('login') }}" class="btn btn-secondary mt-7 w-full">Start free</a>
                        </div>

                        <div class="l-price l-price-popular reveal">
                            <span class="l-price-badge">Most popular</span>
                            <p class="text-sm font-bold uppercase tracking-wide text-indigo-500">Pro</p>
                            <p class="mt-3 flex items-baseline gap-1">
                                <span class="text-4xl font-extrabold tracking-tight text-slate-900 dark:text-white">$29</span>
                                <span class="text-sm font-medium text-slate-500 dark:text-slate-400">/ month</span>
                            </p>
                            <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">For growing teams with real traffic.</p>
                            <ul class="mt-5 space-y-2.5 text-sm text-slate-700 dark:text-slate-300">
                                <li class="l-tick">3 sites · 2,000 messages / month</li>
                                <li class="l-tick">Unlimited documents</li>
                                <li class="l-tick">Visitor email capture &amp; leads</li>
                                <li class="l-tick">Analytics + CSV export</li>
                                <li class="l-tick">History kept 12 months</li>
                            </ul>
                            <a href="{{ route('login') }}" class="btn btn-primary mt-7 w-full">Create your support bot</a>
                        </div>

                        <div class="l-price reveal">
                            <p class="text-sm font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">Scale</p>
                            <p class="mt-3 flex items-baseline gap-1">
                                <span class="text-4xl font-extrabold tracking-tight text-slate-900 dark:text-white">$99</span>
                                <span class="text-sm font-medium text-slate-500 dark:text-slate-400">/ month</span>
                            </p>
                            <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">For support orgs that live in the tool.</p>
                            <ul class="mt-5 space-y-2.5 text-sm text-slate-700 dark:text-slate-300">
                                <li class="l-tick">10 sites · 10,000 messages / month</li>
                                <li class="l-tick">Workspaces with roles &amp; invitations</li>
                                <li class="l-tick">Priority support &amp; onboarding</li>
                                <li class="l-tick">Configurable data retention</li>
                                <li class="l-tick">History kept forever</li>
                            </ul>
                            <a href="{{ route('login') }}" class="btn btn-secondary mt-7 w-full">Talk to us</a>
                        </div>
                    </div>
                </div>
            </section>

            {{-- ── FAQ ────────────────────────────────────────────────── --}}
            <section class="l-section border-y border-slate-200/80 bg-slate-50/60 py-16 dark:border-white/10 dark:bg-white/[0.015] lg:py-24" id="faq">
                <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                    <div class="reveal text-center">
                        <span class="text-xs font-bold uppercase tracking-[0.18em] text-indigo-500">FAQ</span>
                        <h2 class="mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl">Questions, answered.</h2>
                    </div>

                    <div class="mt-10 space-y-3">
                        <details class="l-faq reveal" open>
                            <summary>How long does setup take?<span class="l-faq-chevron" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg></span></summary>
                            <p>Most teams go live in about five minutes: create an account, upload one or two documents, pick a name and greeting, then paste the install snippet on your site.</p>
                        </details>

                        <details class="l-faq reveal">
                            <summary>Which frameworks and sites does it work with?<span class="l-faq-chevron" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg></span></summary>
                            <p>Any site that can load a script tag: React, Next.js, Vue, Svelte, Laravel/Blade, WordPress or plain HTML. There's no build step — the widget mounts itself.</p>
                        </details>

                        <details class="l-faq reveal">
                            <summary>What files can I upload?<span class="l-faq-chevron" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg></span></summary>
                            <p>PDF, DOCX, Markdown and plain text — help-center exports, product handbooks, policy documents, pricing sheets. {{ config('app.name') }} chunks and indexes them so every answer can cite the source.</p>
                        </details>

                        <details class="l-faq reveal">
                            <summary>Is this just a PDF reader?<span class="l-faq-chevron" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg></span></summary>
                            <p>No — {{ config('app.name') }} is a support assistant, not a document viewer. Files, web pages and notes are chunked and indexed behind the scenes so the assistant can answer your customers' questions from your approved sources, cite them, and escalate to a human when it can't.</p>
                        </details>

                        <details class="l-faq reveal">
                            <summary>Can I sign in with Google?<span class="l-faq-chevron" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg></span></summary>
                            <p>Yes — Google Login is supported. Sign-in is matched to your verified Google email so your workspace, credits and documents stay exactly where you left them.</p>
                        </details>

                        <details class="l-faq reveal">
                            <summary>How do I install the widget?<span class="l-faq-chevron" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg></span></summary>
                            <p>Copy the one-line snippet from your dashboard (it contains your public site key) and paste it before <code class="l-inline-code">&lt;/body&gt;</code>. Save, refresh, done — the launcher appears on every page.</p>
                        </details>

                        <details class="l-faq reveal">
                            <summary>Is my data secure?<span class="l-faq-chevron" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg></span></summary>
                            <p>Documents and conversations are isolated per workspace, encrypted in transit and at rest, and gated by role-based access. Retention windows and full export/delete controls are in your settings. <a class="font-semibold text-indigo-500 hover:underline" href="{{ route('privacy.policy') }}">Read the privacy policy</a>.</p>
                        </details>

                        <details class="l-faq reveal">
                            <summary>Does it make things up?<span class="l-faq-chevron" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg></span></summary>
                            <p>The assistant only answers from your approved sources and shows which one it used. When the answer isn't in your knowledge base it declines politely — and you can escalate to a human or mark it as a gap to fill.</p>
                        </details>
                    </div>
                </div>
            </section>

            {{-- ── Final CTA ──────────────────────────────────────────── --}}
            <section class="l-section relative overflow-hidden py-20 lg:py-28">
                <div class="l-glow left-1/4 top-6 h-[22rem] w-[22rem] bg-indigo-600/25" aria-hidden="true"></div>
                <div class="l-glow right-1/4 bottom-0 h-[20rem] w-[20rem] bg-violet-600/20" aria-hidden="true"></div>

                <div class="relative mx-auto max-w-4xl px-4 text-center sm:px-6 lg:px-8">
                    <div class="reveal flex justify-center gap-4" aria-hidden="true">
                        <x-blobatar :name="'DocuMind'" :size="56" data-blobatar-expression="happy" class="l-float" />
                        <x-blobatar :name="'DocuMind'" :size="72" data-blobatar-expression="wink" data-blobatar-animate="live" class="l-float l-float-2 drop-shadow-[0_18px_36px_rgba(99,102,241,0.5)]" />
                        <x-blobatar :name="'DocuMind'" :size="56" data-blobatar-expression="love" class="l-float" />
                    </div>

                    <h2 class="reveal mt-8 text-3xl font-extrabold tracking-tight sm:text-4xl lg:text-5xl">
                        Launch your branded<br class="hidden sm:block"> support assistant today.
                    </h2>
                    <p class="reveal mx-auto mt-4 max-w-xl text-base leading-relaxed text-slate-600 dark:text-slate-400">
                        Five minutes from now, your visitors could be getting cited, on-brand answers — and
                        your team could be triaging only what actually needs a human.
                    </p>

                    <div class="reveal mt-8 flex flex-wrap items-center justify-center gap-3">
                        <a href="{{ route('login') }}" class="btn btn-primary h-11 px-6 text-[0.95rem]">Create your support bot</a>
                        <a href="#demo" class="btn btn-secondary h-11 px-6 text-[0.95rem]">View live demo</a>
                        <a href="{{ route('login') }}" class="btn btn-ghost h-11 px-4 text-[0.95rem]">Start free</a>
                    </div>
                </div>
            </section>
        </main>

        {{-- ── Footer ────────────────────────────────────────────────── --}}
        <footer class="border-t border-slate-200/80 bg-slate-50/70 dark:border-white/10 dark:bg-white/[0.02]">
            <div class="mx-auto flex max-w-7xl flex-col gap-8 px-4 py-10 sm:px-6 md:flex-row md:items-start md:justify-between lg:px-8">
                <div class="max-w-xs">
                    <a href="{{ route('home') }}" class="group inline-flex items-center gap-2.5">
                        <x-brand-mark :size="28" class="transition-transform group-hover:scale-105" />
                        <span class="text-sm font-bold tracking-tight text-slate-900 dark:text-white">{{ config('app.name') }}</span>
                    </a>
                    <p class="mt-3 text-sm leading-relaxed text-slate-600 dark:text-slate-400">
                        The customizable AI customer-support platform — grounded in your approved knowledge, wearing your brand.
                    </p>
                </div>

                <nav class="grid grid-cols-2 gap-x-10 gap-y-2 text-sm sm:grid-cols-3" aria-label="Footer">
                    <a href="#product" class="text-slate-600 hover:text-indigo-500 dark:text-slate-400">How it works</a>
                    <a href="#features" class="text-slate-600 hover:text-indigo-500 dark:text-slate-400">Features</a>
                    <a href="#use-cases" class="text-slate-600 hover:text-indigo-500 dark:text-slate-400">Use cases</a>
                    <a href="#pricing" class="text-slate-600 hover:text-indigo-500 dark:text-slate-400">Pricing</a>
                    <a href="#faq" class="text-slate-600 hover:text-indigo-500 dark:text-slate-400">FAQ</a>
                    <a href="{{ route('privacy.policy') }}" class="text-slate-600 hover:text-indigo-500 dark:text-slate-400">Privacy policy</a>
                    <a href="{{ route('login') }}" class="text-slate-600 hover:text-indigo-500 dark:text-slate-400">Sign in</a>
                </nav>
            </div>

            <div class="border-t border-slate-200/80 dark:border-white/10">
                <p class="mx-auto max-w-7xl px-4 py-5 text-xs text-slate-500 sm:px-6 lg:px-8 dark:text-slate-500">
                    © {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
                </p>
            </div>
        </footer>
    </body>
</html>
