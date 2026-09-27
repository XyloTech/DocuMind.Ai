@extends('layouts.app')

@section('title', $site->name.' · Widget')

@section('content')
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-2.5">
                <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">{{ $site->name }}</h1>
                <span class="rounded-full border px-2.5 py-0.5 text-xs font-semibold {{ $site->isLive()
                    ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-400'
                    : 'border-slate-200 bg-slate-50 text-slate-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-500' }}">
                    {{ $site->isLive() ? 'Live' : 'Paused' }}
                </span>
            </div>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $site->domain ?: 'No domain configured' }} &middot; Key: <code class="rounded bg-slate-100 px-1 py-0.5 text-xs font-mono dark:bg-white/10">{{ $site->site_key }}</code></p>
        </div>

        <div class="flex items-center gap-2">
            <a
                href="{{ route('widget.analytics', $site) }}"
                class="btn btn-secondary btn-sm inline-flex items-center gap-1.5"
            >
                <svg class="h-3.5 w-3.5 text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" x2="18" y1="20" y2="10"/>
                    <line x1="12" x2="12" y1="20" y2="4"/>
                    <line x1="6" x2="6" y1="20" y2="14"/>
                </svg>
                <span>Analytics</span>
            </a>

            <a href="{{ route('widget.leads.index', $site) }}" class="btn btn-secondary btn-sm inline-flex items-center gap-1.5">
                <span>Visitor inbox</span>
            </a>

            <a href="{{ route('widget.index') }}" class="btn btn-ghost btn-sm">
                &larr; All sites
            </a>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_380px]">
        <div class="min-w-0 space-y-6">
            @include('widget.partials.install-embed')

            <form method="POST" action="{{ route('widget.update', $site) }}" class="space-y-6" data-widget-customization>
            @csrf
            @method('PUT')

            @if ($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300">
                    <p class="font-bold">Could not save settings</p>
                    <ul class="mt-1 list-inside list-disc">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Knowledge Base Selection --}}
            <section class="rounded-2xl border border-slate-200/80 bg-white dark:bg-[#0d0f15] p-5 shadow-xs dark:border-white/10">
                <h2 class="text-sm font-bold text-slate-900 dark:text-white">Product support knowledge</h2>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">These internal sources inform customer answers about your product, services, and policies.</p>

                @if ($documents->isEmpty())
                    <p class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300">
                        No processed support knowledge yet. <a href="{{ route('dashboard') }}" class="font-bold underline">Add a PDF source</a> to activate the assistant.
                    </p>
                @else
                    <ul class="mt-3.5 grid gap-2.5 sm:grid-cols-2">
                        @foreach ($documents as $document)
                            <li>
                                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200/80 p-3 transition hover:border-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-500/10/30 dark:border-white/10 dark:hover:border-indigo-500/50 dark:hover:bg-white/5">
                                    <input
                                        type="checkbox"
                                        name="documents[]"
                                        value="{{ $document->getKey() }}"
                                        @checked($site->documents->contains($document))
                                        class="mt-1 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 dark:border-white/20 dark:bg-black"
                                    >
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-xs font-semibold text-slate-800 dark:text-slate-200">{{ $document->filename }}</span>
                                        <span class="block text-[11px] text-slate-400 dark:text-slate-400">
                                            {{ $document->chunk_count }} chunks &middot; {{ $document->page_count }} pages
                                        </span>
                                    </span>
                                </label>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- Customization & Branding --}}
            <section class="rounded-2xl border border-slate-200/80 bg-white dark:bg-[#0d0f15] p-5 shadow-xs dark:border-white/10">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-sm font-bold text-slate-900 dark:text-white">Appearance & Personality</h2>
                    <button type="button" data-widget-reset class="btn btn-ghost btn-sm inline-flex items-center gap-1.5">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>
                        </svg>
                        Reset appearance
                    </button>
                </div>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="name" class="block text-xs font-semibold text-slate-600 dark:text-slate-300">Site name</label>
                        <input
                            id="name"
                            name="name"
                            value="{{ old('name', $site->name) }}"
                            required
                            maxlength="80"
                            class="mt-1.5 w-full rounded-xl border border-slate-200/80 bg-slate-50/80 px-3.5 py-2 text-xs text-slate-900 focus:border-indigo-500 focus:bg-white dark:focus:bg-white/10 focus:outline-none dark:border-white/10 dark:bg-white/5 dark:text-white"
                        >
                    </div>

                    <div>
                        <label for="bot_name" class="block text-xs font-semibold text-slate-600 dark:text-slate-300">Assistant Name</label>
                        <input
                            id="bot_name"
                            name="bot_name"
                            value="{{ old('bot_name', $site->bot_name) }}"
                            maxlength="60"
                            class="mt-1.5 w-full rounded-xl border border-slate-200/80 bg-slate-50/80 px-3.5 py-2 text-xs text-slate-900 focus:border-indigo-500 focus:bg-white dark:focus:bg-white/10 focus:outline-none dark:border-white/10 dark:bg-white/5 dark:text-white"
                        >
                    </div>

                    <div>
                        <label for="domain" class="block text-xs font-semibold text-slate-600 dark:text-slate-300">Authorized Domain (Optional)</label>
                        <input
                            id="domain"
                            name="domain"
                            value="{{ old('domain', $site->domain) }}"
                            maxlength="190"
                            placeholder="example.com"
                            class="mt-1.5 w-full rounded-xl border border-slate-200/80 bg-slate-50/80 px-3.5 py-2 text-xs text-slate-900 focus:border-indigo-500 focus:bg-white dark:focus:bg-white/10 focus:outline-none dark:border-white/10 dark:bg-white/5 dark:text-white"
                        >
                    </div>

                    <div class="sm:col-span-2">
                        <label for="greeting" class="block text-xs font-semibold text-slate-600 dark:text-slate-300">Initial Greeting Message</label>
                        <input
                            id="greeting"
                            name="greeting"
                            value="{{ old('greeting', $site->greeting) }}"
                            maxlength="255"
                            placeholder="Hi! I can help with the product. What do you need help with?"
                            class="mt-1.5 w-full rounded-xl border border-slate-200/80 bg-slate-50/80 px-3.5 py-2 text-xs text-slate-900 focus:border-indigo-500 focus:bg-white dark:focus:bg-white/10 focus:outline-none dark:border-white/10 dark:bg-white/5 dark:text-white"
                        >
                    </div>

                    <div>
                        <label for="accent_color" class="block text-xs font-semibold text-slate-600 dark:text-slate-300">Accent Colour</label>
                        <div class="mt-1.5 flex items-center gap-3">
                            <input
                                id="accent_color"
                                name="accent_color"
                                type="color"
                                value="{{ old('accent_color', $site->accent_color) }}"
                                class="h-9 w-12 cursor-pointer rounded-lg border border-slate-200 bg-transparent p-0.5 dark:border-white/10"
                            >
                            <span class="text-xs text-slate-500 dark:text-slate-400">Bubble & button accent</span>
                        </div>
                    </div>

                    <div>
                        <label for="theme" class="block text-xs font-semibold text-slate-600 dark:text-slate-300">Widget Theme</label>
                        <select id="theme" name="theme" class="mt-1.5 w-full rounded-xl border border-slate-200/80 bg-slate-50/80 px-3 py-2 text-xs text-slate-900 focus:border-indigo-500 focus:bg-white dark:focus:bg-white/10 focus:outline-none dark:border-white/10 dark:bg-white/5 dark:text-white">
                            @foreach (['dark' => 'Dark', 'light' => 'Light', 'system' => 'Match visitor device'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('theme', $site->theme) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="launcher_icon" class="block text-xs font-semibold text-slate-600 dark:text-slate-300">Fallback Icon</label>
                        <select id="launcher_icon" name="launcher_icon" class="mt-1.5 w-full rounded-xl border border-slate-200/80 bg-slate-50/80 px-3 py-2 text-xs text-slate-900 focus:border-indigo-500 focus:bg-white dark:focus:bg-white/10 focus:outline-none dark:border-white/10 dark:bg-white/5 dark:text-white">
                            @foreach (['chat' => 'Chat bubble (recommended)', 'support' => 'Support headset', 'brand' => 'Brand mark', 'spark' => 'Spark'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('launcher_icon', $site->launcher_icon) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">The assistant avatar is shown in the launcher; this icon appears only if the avatar cannot render.</p>
                    </div>

                    <div>
                        <label for="launcher_label" class="block text-xs font-semibold text-slate-600 dark:text-slate-300">Launcher Label</label>
                        <input
                            id="launcher_label"
                            name="launcher_label"
                            type="text"
                            value="{{ old('launcher_label', $site->launcher_label) }}"
                            maxlength="40"
                            list="launcher-label-ideas"
                            placeholder="Need help?"
                            aria-describedby="launcher_label_help"
                            class="mt-1.5 w-full rounded-xl border border-slate-200/80 bg-slate-50/80 px-3.5 py-2 text-xs text-slate-900 focus:border-indigo-500 focus:bg-white dark:focus:bg-white/10 focus:outline-none dark:border-white/10 dark:bg-white/5 dark:text-white"
                        >
                        <datalist id="launcher-label-ideas">
                            <option value="Need help?"></option>
                            <option value="Chat with us"></option>
                            <option value="How can we help?"></option>
                            <option value="Ask us anything"></option>
                        </datalist>
                        <p id="launcher_label_help" class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">Shown beside the bubble so visitors know what it does.</p>
                    </div>

                    <div>
                        <label for="launcher_animation" class="block text-xs font-semibold text-slate-600 dark:text-slate-300">Entrance Animation</label>
                        <select id="launcher_animation" name="launcher_animation" class="mt-1.5 w-full rounded-xl border border-slate-200/80 bg-slate-50/80 px-3 py-2 text-xs text-slate-900 focus:border-indigo-500 focus:bg-white dark:focus:bg-white/10 focus:outline-none dark:border-white/10 dark:bg-white/5 dark:text-white">
                            @foreach (['pulse' => 'Soft pulse (3 times)', 'bounce' => 'Friendly bounce', 'fade' => 'Slide & fade', 'none' => 'No animation'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('launcher_animation', $site->launcher_animation) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">Plays once when the widget loads. Visitors with reduced-motion enabled always get the calm version.</p>
                    </div>

                    {{-- Assistant Avatar: the Blobatar shown in the launcher, chat header,
                         typing indicator and every reply. Same seed, same face, on every
                         page the widget is installed on. --}}
                    <div class="sm:col-span-2 rounded-xl border border-slate-200/80 bg-slate-50/60 p-4 dark:border-white/10 dark:bg-white/[0.03]">
                        <div class="flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="text-xs font-bold text-slate-900 dark:text-white">Assistant Avatar</h3>
                                <p class="mt-0.5 text-[11px] leading-relaxed text-slate-500 dark:text-slate-400">
                                    A deterministic creature generated from the seed below. Blank fields are derived from the assistant's name, so the same name always draws the same face.
                                </p>
                            </div>
                            <x-blobatar
                                data-avatar-live
                                :name="$site->blobatar_seed ?: ($site->bot_name ?: 'Assistant')"
                                :size="48"
                                background="squircle"
                                class="ring-1 ring-slate-200 dark:ring-white/10"
                            />
                        </div>

                        <div class="mt-3.5 grid gap-4 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label for="blobatar_seed" class="block text-xs font-semibold text-slate-600 dark:text-slate-300">Avatar Seed</label>
                                <input
                                    id="blobatar_seed"
                                    name="blobatar_seed"
                                    type="text"
                                    value="{{ old('blobatar_seed', $site->blobatar_seed) }}"
                                    maxlength="64"
                                    placeholder="Derived from Assistant Name"
                                    class="mt-1.5 w-full rounded-xl border border-slate-200/80 bg-white px-3.5 py-2 text-xs text-slate-900 focus:border-indigo-500 focus:bg-white dark:focus:bg-white/10 focus:outline-none dark:border-white/10 dark:bg-white/5 dark:text-white"
                                >
                                <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">Change the seed to mint a different character. Leave blank to follow the Assistant Name.</p>
                            </div>

                            <div>
                                <label for="blobatar_size" class="block text-xs font-semibold text-slate-600 dark:text-slate-300">Avatar Size</label>
                                <input
                                    id="blobatar_size"
                                    name="blobatar_size"
                                    type="number"
                                    min="24"
                                    max="64"
                                    value="{{ old('blobatar_size', $site->blobatar_size) }}"
                                    class="mt-1.5 w-full rounded-xl border border-slate-200/80 bg-white px-3.5 py-2 text-xs text-slate-900 focus:border-indigo-500 focus:bg-white dark:focus:bg-white/10 focus:outline-none dark:border-white/10 dark:bg-white/5 dark:text-white"
                                >
                                <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">24–64 px. Shown in the chat header; messages use a smaller derived size.</p>
                            </div>

                            <div>
                                <label for="blobatar_background" class="block text-xs font-semibold text-slate-600 dark:text-slate-300">Avatar Backdrop</label>
                                <select id="blobatar_background" name="blobatar_background" class="mt-1.5 w-full rounded-xl border border-slate-200/80 bg-white px-3 py-2 text-xs text-slate-900 focus:border-indigo-500 focus:bg-white dark:focus:bg-white/10 focus:outline-none dark:border-white/10 dark:bg-white/5 dark:text-white">
                                    @foreach (['squircle' => 'Squircle (soft square)', 'circle' => 'Circle', 'square' => 'Square', 'none' => 'No backdrop (transparent)'] as $value => $label)
                                        <option value="{{ $value }}" @selected(old('blobatar_background', $site->blobatar_background) === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label for="blobatar_hue" class="block text-xs font-semibold text-slate-600 dark:text-slate-300">Colour Hue</label>
                                <div class="mt-1.5 flex items-center gap-2.5">
                                    <input
                                        id="blobatar_hue"
                                        name="blobatar_hue"
                                        type="range"
                                        min="0"
                                        max="360"
                                        step="1"
                                        value="{{ old('blobatar_hue', $site->blobatar_hue ?? 210) }}"
                                        data-range
                                        aria-describedby="blobatar_hue_help"
                                        class="h-1.5 flex-1 cursor-pointer appearance-none rounded-full bg-slate-200 accent-indigo-600 dark:bg-white/10"
                                    >
                                    <output for="blobatar_hue" data-range-output class="w-12 text-right text-[11px] font-semibold tabular-nums text-slate-600 dark:text-slate-300">{{ old('blobatar_hue', $site->blobatar_hue ?? 210) }}°</output>
                                    <label class="flex shrink-0 items-center gap-1.5 text-[11px] font-medium text-slate-500 dark:text-slate-400">
                                        <input type="checkbox" data-auto-toggle="blobatar_hue" @checked(old('blobatar_hue', $site->blobatar_hue) === null) class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 dark:border-white/20 dark:bg-black">
                                        Auto
                                    </label>
                                </div>
                                <p id="blobatar_hue_help" class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">Auto derives the hue from the seed; uncheck to pin a colour.</p>
                            </div>

                            <div>
                                <label for="blobatar_tone" class="block text-xs font-semibold text-slate-600 dark:text-slate-300">Colour Tone</label>
                                <div class="mt-1.5 flex items-center gap-2.5">
                                    <input
                                        id="blobatar_tone"
                                        name="blobatar_tone"
                                        type="range"
                                        min="0"
                                        max="0.999"
                                        step="0.001"
                                        value="{{ old('blobatar_tone', $site->blobatar_tone ?? 0.5) }}"
                                        data-range
                                        aria-describedby="blobatar_tone_help"
                                        class="h-1.5 flex-1 cursor-pointer appearance-none rounded-full bg-slate-200 accent-indigo-600 dark:bg-white/10"
                                    >
                                    <output for="blobatar_tone" data-range-output class="w-12 text-right text-[11px] font-semibold tabular-nums text-slate-600 dark:text-slate-300">{{ old('blobatar_tone', $site->blobatar_tone ?? 0.5) }}</output>
                                    <label class="flex shrink-0 items-center gap-1.5 text-[11px] font-medium text-slate-500 dark:text-slate-400">
                                        <input type="checkbox" data-auto-toggle="blobatar_tone" @checked(old('blobatar_tone', $site->blobatar_tone) === null) class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 dark:border-white/20 dark:bg-black">
                                        Auto
                                    </label>
                                </div>
                                <p id="blobatar_tone_help" class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">0 is pale, 0.999 is ink. Auto picks a tone that keeps the face legible.</p>
                            </div>

                            <div>
                                <label for="blobatar_expression" class="block text-xs font-semibold text-slate-600 dark:text-slate-300">Expression</label>
                                <select id="blobatar_expression" name="blobatar_expression" class="mt-1.5 w-full rounded-xl border border-slate-200/80 bg-white px-3 py-2 text-xs text-slate-900 focus:border-indigo-500 focus:bg-white dark:focus:bg-white/10 focus:outline-none dark:border-white/10 dark:bg-white/5 dark:text-white">
                                    @foreach (['idle' => 'Natural', 'happy' => 'Happy', 'sad' => 'Sad', 'mad' => 'Angry', 'surprised' => 'Surprised', 'wink' => 'Wink', 'sleepy' => 'Sleepy', 'smug' => 'Smug', 'unsure' => 'Unsure', 'scared' => 'Scared', 'love' => 'In love', 'shy' => 'Shy', 'sick' => 'Sick', 'thinking' => 'Thinking'] as $value => $label)
                                        <option value="{{ $value }}" @selected(old('blobatar_expression', $site->blobatar_expression) === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">The typing indicator always wears <strong>Thinking</strong> while the assistant works.</p>
                            </div>

                            <div>
                                <label for="blobatar_animation" class="block text-xs font-semibold text-slate-600 dark:text-slate-300">Idle Animation</label>
                                <select id="blobatar_animation" name="blobatar_animation" class="mt-1.5 w-full rounded-xl border border-slate-200/80 bg-white px-3 py-2 text-xs text-slate-900 focus:border-indigo-500 focus:bg-white dark:focus:bg-white/10 focus:outline-none dark:border-white/10 dark:bg-white/5 dark:text-white">
                                    @foreach (['live' => 'Always alive (subtle breathing & blinking)', 'hover' => 'Wake on hover', 'static' => 'Still'] as $value => $label)
                                        <option value="{{ $value }}" @selected(old('blobatar_animation', $site->blobatar_animation) === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">Motion is automatically disabled for visitors with reduced-motion enabled.</p>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label for="logo_url" class="block text-xs font-semibold text-slate-600 dark:text-slate-300">Logo URL (Optional)</label>
                        <input
                            id="logo_url"
                            name="logo_url"
                            type="url"
                            value="{{ old('logo_url', $site->logo_url) }}"
                            maxlength="255"
                            placeholder="https://example.com/logo.png"
                            class="mt-1.5 w-full rounded-xl border border-slate-200/80 bg-slate-50/80 px-3.5 py-2 text-xs text-slate-900 focus:border-indigo-500 focus:bg-white dark:focus:bg-white/10 focus:outline-none dark:border-white/10 dark:bg-white/5 dark:text-white"
                        >
                    </div>

                    <div>
                        <label for="position" class="block text-xs font-semibold text-slate-600 dark:text-slate-300">Bubble Position</label>
                        <select
                            id="position"
                            name="position"
                            class="mt-1.5 w-full rounded-xl border border-slate-200/80 bg-slate-50/80 px-3 py-2 text-xs text-slate-900 focus:border-indigo-500 focus:bg-white dark:focus:bg-white/10 focus:outline-none dark:border-white/10 dark:bg-white/5 dark:text-white"
                        >
                            @foreach ($positions as $position)
                                <option value="{{ $position }}" @selected(old('position', $site->position->value) === $position)>
                                    {{ str_replace('-', ' ', ucfirst($position)) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="monthly_quota" class="block text-xs font-semibold text-slate-600 dark:text-slate-300">Monthly Message Quota</label>
                        <input
                            id="monthly_quota"
                            name="monthly_quota"
                            type="number"
                            min="0"
                            max="1000000"
                            value="{{ old('monthly_quota', $site->monthly_quota) }}"
                            class="mt-1.5 w-full rounded-xl border border-slate-200/80 bg-slate-50/80 px-3.5 py-2 text-xs text-slate-900 focus:border-indigo-500 focus:bg-white dark:focus:bg-white/10 focus:outline-none dark:border-white/10 dark:bg-white/5 dark:text-white"
                        >
                    </div>

                    <div class="flex items-center gap-2 sm:col-span-2 pt-2">
                        <label class="flex items-center gap-2 text-xs font-medium text-slate-700 dark:text-slate-300">
                            <input
                                type="checkbox"
                                name="collect_email"
                                value="1"
                                @checked(old('collect_email', $site->collect_email))
                                class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 dark:border-white/20 dark:bg-black"
                            >
                            Prompt visitor for email before chat starts
                        </label>
                    </div>
                    <p class="sm:col-span-2 -mt-3 text-xs leading-relaxed text-slate-600 dark:text-slate-300">
                        When enabled, visitors must provide a valid email and agree to share it for this support conversation before they can chat. Captured details and transcripts are available only in the authorized visitor inbox.
                    </p>

                    <div class="sm:col-span-2">
                        <label for="visitor_retention_days" class="block text-xs font-semibold text-slate-700 dark:text-slate-200">Visitor data retention</label>
                        <select id="visitor_retention_days" name="visitor_retention_days" class="mt-1.5 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 focus:border-indigo-600 focus:outline-none focus:ring-2 focus:ring-indigo-600/30 dark:border-white/20 dark:bg-white/5 dark:text-white">
                            @foreach ([30, 90, 180, 365] as $days)
                                <option value="{{ $days }}" @selected((int) old('visitor_retention_days', $site->visitor_retention_days) === $days)>{{ $days }} days</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs leading-relaxed text-slate-600 dark:text-slate-300">Visitor conversations and their transcripts are automatically deleted after this period.</p>
                    </div>

                    <div class="flex items-center gap-2 sm:col-span-2">
                        <label class="flex items-center gap-2 text-xs font-medium text-slate-700 dark:text-slate-300">
                            <input
                                type="checkbox"
                                name="enabled"
                                value="1"
                                @checked(old('enabled', $site->enabled))
                                class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 dark:border-white/20 dark:bg-black"
                            >
                            Widget is enabled and accepting messages
                        </label>
                    </div>
                </div>
            </section>

            <div class="flex items-center gap-3">
                <button type="submit" class="btn btn-primary btn-sm">
                    Save Changes
                </button>
            </div>
            </form>
        </div>

        {{-- Preview & Controls Sidebar --}}
        <aside class="space-y-5">
            {{-- Grok Widget Mockup Preview --}}
            <section class="rounded-2xl border border-slate-200/80 bg-white dark:bg-[#0d0f15] p-5 shadow-xs dark:border-white/10">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-bold text-slate-900 dark:text-white">Widget Preview</h2>
                    <span data-preview-status aria-live="polite" class="text-[10px] font-semibold text-slate-500 dark:text-slate-400">Live preview</span>
                </div>

                {{-- Interactive Mini Chat Preview --}}
                <div data-widget-preview data-preview-theme="{{ $site->theme }}" class="relative mt-4 flex h-72 flex-col justify-end overflow-hidden rounded-2xl border border-slate-200 bg-slate-100/80 p-4 dark:border-white/10 dark:bg-black/60">
                    {{-- Mini chat window popup --}}
                    <div data-preview-panel class="mb-3 flex w-[88%] flex-col self-end rounded-xl border border-slate-200/80 bg-white dark:bg-[#0d0f15] p-3 shadow-lg dark:border-white/10">
                        <div class="flex items-center gap-2 border-b border-slate-100 pb-2 dark:border-white/10">
                            <div data-preview-avatar class="flex h-5 w-5 items-center justify-center overflow-hidden rounded-md text-[10px] font-bold text-white" style="background: {{ $site->accent_color }}">
                                @if ($site->logo_url)
                                    <img src="{{ $site->logo_url }}" alt="" class="h-full w-full rounded-md object-cover">
                                @else
                                    <x-blobatar :name="$site->bot_name ?: 'Assistant'" :size="20" background="squircle" />
                                @endif
                            </div>
                            <span data-preview-title class="truncate text-[11px] font-bold text-slate-800 dark:text-white">{{ $site->bot_name }}</span>
                            <span class="ml-auto h-2 w-2 rounded-full bg-emerald-500"></span>
                        </div>
                        <div class="mt-2 space-y-1.5 text-[11px]">
                            <div data-preview-greeting class="rounded-lg bg-slate-50 p-2 text-slate-700 dark:bg-white/5 dark:text-slate-300">
                                {{ $site->greeting ?: 'Hi! I can help with the product. What do you need help with?' }}
                            </div>
                        </div>
                    </div>

                    {{-- Launcher Button + label pill, mirroring the real widget --}}
                    <div data-preview-launcher-row class="flex items-center gap-2" style="justify-content: {{ $site->position->value === 'bottom-left' ? 'flex-start' : 'flex-end' }}">
                        @if ($site->position->value !== 'bottom-left')
                            <span data-preview-hint class="max-w-[9rem] truncate rounded-full border border-slate-200 bg-white px-2.5 py-1 text-[10px] font-semibold text-slate-700 shadow-sm dark:border-white/10 dark:bg-[#12151f] dark:text-slate-200">{{ $site->launcher_label }}</span>
                        @endif
                        <div data-preview-launcher class="flex h-11 w-11 items-center justify-center overflow-hidden rounded-full text-white shadow-xl ring-2 ring-white/20 transition-transform hover:scale-105" style="background: {{ $site->accent_color }}">
                            <span data-preview-launcher-blob class="block h-7 w-7 overflow-hidden rounded-full"></span>
                        </div>
                        @if ($site->position->value === 'bottom-left')
                            <span data-preview-hint class="max-w-[9rem] truncate rounded-full border border-slate-200 bg-white px-2.5 py-1 text-[10px] font-semibold text-slate-700 shadow-sm dark:border-white/10 dark:bg-[#12151f] dark:text-slate-200">{{ $site->launcher_label }}</span>
                        @endif
                    </div>
                </div>
            </section>

            {{-- Quota Usage --}}
            <section class="rounded-2xl border border-slate-200/80 bg-white dark:bg-[#0d0f15] p-5 shadow-xs dark:border-white/10">
                <h2 class="text-sm font-bold text-slate-900 dark:text-white">Monthly Quota</h2>
                <div class="mt-2 flex items-baseline justify-between">
                    <p class="text-2xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                        {{ $site->messagesUsedThisPeriod() }}
                    </p>
                    <span class="text-xs text-slate-400 dark:text-slate-500">/ {{ number_format($site->monthly_quota) }} messages</span>
                </div>
                <div class="mt-2.5 h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-white/10">
                    <div
                        class="h-full rounded-full transition-all"
                        style="width: {{ $site->monthly_quota > 0 ? min(100, ($site->messagesUsedThisPeriod() / $site->monthly_quota) * 100) : 0 }}%; background: {{ $site->accent_color }}"
                    ></div>
                </div>

                <form method="POST" action="{{ route('widget.rotate-key', $site) }}" data-rotate-key class="mt-4">
                    @csrf
                    <button type="submit" class="btn btn-danger btn-sm">
                        Rotate Site Secret Key &rarr;
                    </button>
                </form>
            </section>

            {{-- Danger Zone --}}
            <section class="rounded-2xl border border-rose-200/80 bg-rose-50/50 p-5 shadow-xs dark:border-rose-500/20 dark:bg-rose-500/5">
                <h2 class="text-sm font-bold text-rose-800 dark:text-rose-400">Delete Site</h2>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-500">
                    Permanently deletes this widget, its visitor history and analytics. Your documents will not be affected.
                </p>

                <form method="POST" action="{{ route('widget.destroy', $site) }}" class="mt-3.5" data-delete-site>
                    @csrf
                    @method('DELETE')

                    <button type="submit" class="btn btn-danger btn-sm">
                        Delete Site
                    </button>
                </form>
            </section>
        </aside>
    </div>
@endsection