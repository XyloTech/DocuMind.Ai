@extends('layouts.app')

@section('title', 'Support assistants · '.config('app.name'))

@section('content')
    @php
        $siteStatuses = $sites->mapWithKeys(fn ($site) => [$site->getKey() => $site->isLive()]);
        $activeSiteCount = $siteStatuses->filter()->count();
        $inactiveSiteCount = $sites->count() - $activeSiteCount;
    @endphp

    <div class="mb-6 flex flex-wrap items-end justify-between gap-4 border-b border-slate-200 pb-5 dark:border-white/10">
        <div>
            <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Workspace / Assistants</p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-900 dark:text-white">Support assistants</h1>
            <p class="mt-1 max-w-2xl text-sm text-slate-600 dark:text-slate-400">Manage deployments, traffic limits, and support knowledge for each site.</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <div class="flex items-center gap-3 text-xs">
                <span class="inline-flex items-center gap-1.5 text-emerald-700 dark:text-emerald-400"><span class="h-2 w-2 rounded-full bg-emerald-500"></span>{{ $activeSiteCount }} active</span>
                <span class="inline-flex items-center gap-1.5 text-rose-700 dark:text-rose-400"><span class="h-2 w-2 rounded-full bg-rose-500"></span>{{ $inactiveSiteCount }} inactive</span>
            </div>
            <a href="#create-assistant" class="btn btn-primary btn-sm">New assistant</a>
        </div>
    </div>

    <section id="create-assistant" class="mb-6 rounded-lg border border-slate-200 bg-white dark:border-white/10 dark:bg-[#0d0f15]">
        <div class="border-b border-slate-200 px-4 py-3 dark:border-white/10">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Create an assistant</h2>
        </div>

        <form method="POST" action="{{ route('widget.store') }}" class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[minmax(14rem,1.4fr)_minmax(12rem,1fr)_9rem_auto] lg:items-end">
            @csrf
            <div>
                <label for="site-name" class="block text-xs font-medium text-slate-600 dark:text-slate-300">Site name</label>
                <input id="site-name" name="name" value="{{ old('name') }}" required maxlength="80" placeholder="Acme Help Center" class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/15 dark:border-white/15 dark:bg-[#090b10] dark:text-white dark:placeholder:text-slate-500">
            </div>
            <div>
                <label for="site-domain" class="block text-xs font-medium text-slate-600 dark:text-slate-300">Domain <span class="font-normal text-slate-400">(optional)</span></label>
                <input id="site-domain" name="domain" value="{{ old('domain') }}" maxlength="190" placeholder="acme.com" class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/15 dark:border-white/15 dark:bg-[#090b10] dark:text-white dark:placeholder:text-slate-500">
            </div>
            <div>
                <label for="site-quota" class="block text-xs font-medium text-slate-600 dark:text-slate-300">Monthly quota</label>
                <input id="site-quota" name="monthly_quota" type="number" min="0" max="1000000" value="{{ old('monthly_quota', 1000) }}" class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/15 dark:border-white/15 dark:bg-[#090b10] dark:text-white">
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" @disabled($documents->isEmpty()) class="btn btn-primary btn-sm disabled:cursor-not-allowed disabled:opacity-40">Create</button>
                @if ($documents->isEmpty())
                    <span class="text-xs text-amber-700 dark:text-amber-400">Add a processed source first.</span>
                @endif
            </div>
        </form>
    </section>

    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-white/10 dark:bg-[#0d0f15]">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 dark:border-white/10">
            <div>
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Your assistants</h2>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ $sites->count() }} {{ \Illuminate\Support\Str::plural('assistant', $sites->count()) }} configured</p>
            </div>
            <a href="{{ route('dashboard') }}" class="text-xs font-medium text-slate-600 hover:text-indigo-600 dark:text-slate-300 dark:hover:text-indigo-300">Manage knowledge</a>
        </div>

        @if ($sites->isEmpty())
            <div class="px-4 py-10 text-center">
                <p class="text-sm font-medium text-slate-800 dark:text-slate-200">No assistants configured</p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Create an assistant after adding a processed knowledge source.</p>
            </div>
        @else
            <ul class="divide-y divide-slate-200 dark:divide-white/10">
                @foreach ($sites as $site)
                    @php($siteIsActive = $siteStatuses->get($site->getKey(), false))
                    @php($siteUsage = $site->messagesUsedThisPeriod())
                    <li class="flex flex-col gap-3 px-4 py-4 transition-colors hover:bg-slate-50/70 sm:flex-row sm:items-center dark:hover:bg-white/[0.025]">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-slate-200 bg-slate-50 text-slate-500 dark:border-white/10 dark:bg-white/[0.04] dark:text-slate-300" aria-hidden="true">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <rect x="3" y="4" width="18" height="13" rx="2"/>
                                <path d="M8 21h8m-4-4v4M3 9h18"/>
                            </svg>
                        </span>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <a href="{{ route('widget.show', $site) }}" class="break-words text-sm font-semibold text-slate-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-300">{{ $site->name }}</a>
                                <span @class([
                                    'inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[11px] font-medium',
                                    'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300' => $siteIsActive,
                                    'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300' => ! $siteIsActive,
                                ]) title="{{ $siteIsActive ? 'Enabled with linked knowledge' : 'Disabled or missing linked knowledge' }}">
                                    <span class="h-1.5 w-1.5 rounded-full {{ $siteIsActive ? 'bg-emerald-500' : 'bg-rose-500' }}"></span>
                                    {{ $siteIsActive ? 'Active' : 'Inactive' }}
                                </span>
                            </div>
                            <p class="mt-0.5 break-words text-xs text-slate-500 [overflow-wrap:anywhere] dark:text-slate-400">
                                {{ $site->domain ?: 'Any domain' }}
                                <span aria-hidden="true">·</span> {{ $site->documents->count() }} {{ \Illuminate\Support\Str::plural('source', $site->documents->count()) }}
                                <span aria-hidden="true">·</span> {{ $site->conversations_count }} {{ \Illuminate\Support\Str::plural('conversation', $site->conversations_count) }}
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 sm:justify-end">
                            <span class="text-xs tabular-nums text-slate-500 dark:text-slate-400">{{ number_format($siteUsage) }} / {{ number_format($site->monthly_quota) }} messages</span>
                            <a href="{{ route('widget.show', $site) }}" class="btn btn-secondary btn-sm">Manage</a>
                            <a href="{{ route('widget.analytics', $site) }}" class="btn btn-ghost btn-sm">Activity</a>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <details class="mt-4 rounded-lg border border-slate-200 bg-white px-4 py-3 dark:border-white/10 dark:bg-[#0d0f15]">
        <summary class="cursor-pointer text-sm font-medium text-slate-700 dark:text-slate-200">Installation guide and supported platforms</summary>
        <div class="mt-4 grid gap-5 border-t border-slate-200 pt-4 text-xs text-slate-600 dark:border-white/10 dark:text-slate-400 sm:grid-cols-2">
            <ol class="space-y-2.5">
                <li><strong class="font-semibold text-slate-800 dark:text-slate-200">1.</strong> Create an assistant and connect product and policy knowledge.</li>
                <li><strong class="font-semibold text-slate-800 dark:text-slate-200">2.</strong> Open Manage and copy the installation snippet for your stack.</li>
                <li><strong class="font-semibold text-slate-800 dark:text-slate-200">3.</strong> Verify installation, then test the live preview.</li>
            </ol>
            <div>
                <h3 class="font-semibold text-slate-800 dark:text-slate-200">Supported platforms</h3>
                <p class="mt-1.5 leading-relaxed">HTML, React, Next.js, Vue, Angular, Svelte, WordPress, and other sites that can load a script tag.</p>
                <p class="mt-2 leading-relaxed">The widget runs inside a shadow root to keep its styles separate from your website.</p>
            </div>
        </div>
    </details>
@endsection