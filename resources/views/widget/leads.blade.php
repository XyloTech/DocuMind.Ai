@extends('layouts.app')

@section('title', 'Visitor inbox · '.$site->name)

@section('content')
    <section data-lead-inbox data-refresh-url="{{ request()->fullUrl() }}">
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4 border-b border-slate-200 pb-5 dark:border-white/10">
            <div>
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $site->name }} / Visitors</p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-900 dark:text-white">Visitor inbox</h1>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">Review consented visitor details and support conversations for this website.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span @class([
                    'rounded-full border px-3 py-2 text-xs font-semibold',
                    'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300' => $site->collect_email,
                    'border-slate-300 bg-slate-100 text-slate-700 dark:border-white/20 dark:bg-white/5 dark:text-slate-300' => ! $site->collect_email,
                ])>
                    Email collection {{ $site->collect_email ? 'enabled' : 'disabled' }}
                </span>
                <a href="{{ route('widget.show', $site) }}" class="btn btn-secondary inline-flex min-h-11 items-center">Widget settings</a>
                <a href="{{ route('widget.analytics', $site) }}" class="btn btn-secondary inline-flex min-h-11 items-center">Analytics</a>
            </div>
        </div>

        <div class="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            @foreach ([
                ['key' => 'conversations', 'label' => 'Visitors'],
                ['key' => 'with_email', 'label' => 'Email consent'],
                ['key' => 'open', 'label' => 'Open conversations'],
                ['key' => 'follow_up', 'label' => 'Follow-up pending'],
                ['key' => 'leads', 'label' => 'Classified as lead'],
            ] as $stat)
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-white/15 dark:bg-[#0d0f15]">
                    <p class="text-xs font-medium text-slate-600 dark:text-slate-300">{{ $stat['label'] }}</p>
                    <p data-lead-total="{{ $stat['key'] }}" class="mt-2 text-2xl font-bold tabular-nums text-slate-900 dark:text-white">{{ number_format($totals[$stat['key']]) }}</p>
                </div>
            @endforeach
        </div>

        <form method="GET" action="{{ route('widget.leads.index', $site) }}" class="mb-5 grid gap-2 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-7 dark:border-white/15 dark:bg-[#0d0f15]">
            <label class="sr-only" for="visitor-search">Search exact email or session ID</label>
            <input id="visitor-search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Exact email or session ID" class="min-w-0 rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 placeholder:text-slate-500 focus:border-indigo-600 focus:outline-none focus:ring-2 focus:ring-indigo-600/30 dark:border-white/20 dark:bg-white/5 dark:text-white dark:placeholder:text-slate-400">
            <select name="status" aria-label="Conversation status" class="rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 dark:border-white/20 dark:bg-[#0d0f15] dark:text-white">
                <option value="">All statuses</option>
                @foreach (['open' => 'Open', 'closed' => 'Closed'] as $key => $label)
                    <option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <select name="classification" aria-label="Visitor classification" class="rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 dark:border-white/20 dark:bg-[#0d0f15] dark:text-white">
                <option value="">All classifications</option>
                @foreach (['lead' => 'Lead', 'support' => 'Support'] as $key => $label)
                    <option value="{{ $key }}" @selected(($filters['classification'] ?? '') === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <select name="follow_up_status" aria-label="Follow-up status" class="rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 dark:border-white/20 dark:bg-[#0d0f15] dark:text-white">
                <option value="">All follow-ups</option>
                @foreach (['none' => 'No follow-up', 'pending' => 'Pending', 'contacted' => 'Contacted', 'complete' => 'Complete'] as $key => $label)
                    <option value="{{ $key }}" @selected(($filters['follow_up_status'] ?? '') === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <select name="sort" aria-label="Sort visitor conversations" class="rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 dark:border-white/20 dark:bg-[#0d0f15] dark:text-white">
                @foreach (['newest' => 'Newest first', 'oldest' => 'Oldest first', 'status' => 'Status', 'classification' => 'Classification', 'follow_up_status' => 'Follow-up'] as $key => $label)
                    <option value="{{ $key }}" @selected(($filters['sort'] ?? 'newest') === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <div class="flex gap-2">
                <button type="submit" class="btn btn-secondary min-h-11 flex-1">Filter</button>
                <a href="{{ route('widget.leads.index', $site) }}" class="btn btn-ghost inline-flex min-h-11 items-center">Clear</a>
            </div>
            <a href="{{ route('widget.leads.export', ['site' => $site, ...request()->query()]) }}" class="btn btn-secondary inline-flex min-h-11 items-center justify-center">Export CSV</a>
        </form>

        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <p class="text-xs text-slate-600 dark:text-slate-300">Search matches the exact email address or session ID.</p>
            <div class="flex items-center gap-3">
                <span data-lead-updated role="status" class="text-xs text-slate-600 dark:text-slate-300">Updated just now</span>
                <button type="button" data-lead-refresh class="btn btn-secondary btn-sm min-h-10">Refresh</button>
            </div>
        </div>

        <div class="space-y-3" data-lead-list>
            @include('widget.partials.lead-list')
        </div>

        <div class="mt-5">{{ $conversations->links() }}</div>
        <p class="mt-4 text-xs leading-relaxed text-slate-600 dark:text-slate-300">Visitor emails, session identifiers, and transcripts are encrypted at rest and visible only to authorized site owners and designated workspace staff. Retention is set to {{ $site->visitor_retention_days }} days in widget settings. Exports are audited and are not cached.</p>
    </section>
@endsection