@extends('layouts.app')

@section('title', 'Activity — '.$site->name)

@section('content')
    @include('partials.flash')

    <section
        data-activity
        data-refresh-url="{{ route('widget.analytics', $site) }}"
        data-offline="{{ __('You are offline. Showing the last known numbers.') }}"
        data-error="{{ __('Live refresh failed. Retrying…') }}"
        class="mx-auto max-w-[1400px]"
    >
        <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ $site->name }}</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-900 dark:text-white">Activity</h1>
                <p class="mt-1 max-w-2xl text-sm text-slate-600 dark:text-slate-300">
                    Everything the support assistant did in the last {{ $metrics['range']['days'] }} days:
                    conversations, answers, failures and how visitors engaged with the widget.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <form method="GET" action="{{ route('widget.analytics', $site) }}" class="flex items-center gap-2">
                    <label class="sr-only" for="activity-range">Date range</label>
                    <select
                        id="activity-range"
                        name="days"
                        onchange="this.form.submit()"
                        class="rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 dark:border-white/20 dark:bg-white/5 dark:text-white"
                    >
                        @foreach ([7 => 'Last 7 days', 14 => 'Last 14 days', 30 => 'Last 30 days', 90 => 'Last 90 days'] as $days => $label)
                            <option value="{{ $days }}" @selected($metrics['range']['days'] === $days)>{{ $label }}</option>
                        @endforeach
                    </select>
                </form>

                <a href="{{ route('widget.analytics.export', ['site' => $site, ...request()->query()]) }}"
                   class="btn btn-secondary inline-flex min-h-11 items-center"
                   title="Download the current range as CSV">
                    Export CSV
                </a>
                <a href="{{ route('widget.leads.index', $site) }}"
                   class="btn btn-secondary inline-flex min-h-11 items-center">
                    Visitor inbox
                </a>
                <a href="{{ route('widget.show', $site) }}"
                   class="btn btn-secondary inline-flex min-h-11 items-center">
                    Widget settings
                </a>
            </div>
        </div>

        {{-- Refresh state: loading skeleton, offline notice and error notice share one row. --}}
        <div class="mb-4 flex flex-wrap items-center gap-3 text-xs">
            <span data-activity-status role="status" aria-live="polite" class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-1.5 font-semibold text-slate-600 dark:border-white/15 dark:bg-white/5 dark:text-slate-300">
                <span class="h-2 w-2 rounded-full bg-emerald-500" aria-hidden="true"></span>
                <span data-activity-updated>{{ $metrics['updated_at'] }}</span>
            </span>
            <span data-activity-banner="offline" hidden class="rounded-full border border-amber-300 bg-amber-50 px-3 py-1.5 font-semibold text-amber-800 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-200">
                {{ __('You are offline. Showing the last known numbers.') }}
            </span>
            <span data-activity-banner="error" hidden class="rounded-full border border-rose-300 bg-rose-50 px-3 py-1.5 font-semibold text-rose-800 dark:border-rose-400/30 dark:bg-rose-400/10 dark:text-rose-200">
                {{ __('Live refresh failed. Retrying…') }}
            </span>
        </div>

        {{-- Headline numbers --}}
        <div class="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                ['key' => 'conversations', 'label' => 'Conversations', 'value' => $metrics['totals']['conversations'], 'hint' => 'Chats started in this range.', 'tone' => 'indigo'],
                ['key' => 'messages', 'label' => 'Messages', 'value' => $metrics['totals']['messages'], 'hint' => 'Visitor questions plus assistant replies.', 'tone' => 'indigo'],
                ['key' => 'successful', 'label' => 'Successful responses', 'value' => $metrics['totals']['successful'], 'hint' => 'Answers grounded in the linked support knowledge.', 'tone' => 'emerald'],
                ['key' => 'failed', 'label' => 'Failed responses', 'value' => $metrics['totals']['failed'], 'hint' => 'Answers that errored — reasons are listed below.', 'tone' => 'rose'],
            ] as $card)
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/15 dark:bg-[#0d0f15]"
                     title="{{ $card['hint'] }}">
                    <p class="text-xs font-medium text-slate-600 dark:text-slate-300">{{ $card['label'] }}</p>
                    <p data-metric="totals.{{ $card['key'] }}" data-format="int"
                       class="mt-2 text-3xl font-bold tabular-nums text-slate-900 dark:text-white">{{ number_format($card['value']) }}</p>
                    <p class="mt-1 text-[11px] leading-snug text-slate-500 dark:text-slate-400">{{ $card['hint'] }}</p>
                    <span class="mt-3 block h-1 w-14 rounded-full
                        @if ($card['tone'] === 'emerald') bg-emerald-500
                        @elseif ($card['tone'] === 'rose') bg-rose-500
                        @else bg-indigo-500 @endif" aria-hidden="true"></span>
                </div>
            @endforeach
        </div>

        {{-- Secondary numbers --}}
        <div class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
            @foreach ([
                ['key' => 'fallback', 'label' => 'Fallback replies', 'value' => $metrics['totals']['fallback'], 'format' => 'int', 'hint' => 'No knowledge matched, so the bot answered generally or asked to contact support.'],
                ['key' => 'unanswered', 'label' => 'Unanswered', 'value' => $metrics['totals']['unanswered'], 'format' => 'int', 'hint' => 'Questions that never received a stored answer.'],
                ['key' => 'emails', 'label' => 'Emails captured', 'value' => $metrics['totals']['emails'], 'format' => 'int', 'hint' => 'Visitors who gave an email address with consent.'],
                ['key' => 'avg_response_ms', 'label' => 'Avg response', 'value' => $metrics['totals']['avg_response_ms'], 'format' => 'ms', 'hint' => 'Average time from question to finished answer.'],
                ['key' => 'avg_resolution_minutes', 'label' => 'Avg resolution', 'value' => $metrics['totals']['avg_resolution_minutes'], 'format' => 'min', 'hint' => 'Average time from first message to a closed conversation.'],
                ['key' => 'active', 'label' => 'Active now', 'value' => $metrics['totals']['active'], 'format' => 'int', 'hint' => 'Conversations still open.'],
            ] as $card)
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-white/15 dark:bg-[#0d0f15]" title="{{ $card['hint'] }}">
                    <p class="text-xs font-medium text-slate-600 dark:text-slate-300">{{ $card['label'] }}</p>
                    <p data-metric="totals.{{ $card['key'] }}" data-format="{{ $card['format'] }}"
                       class="mt-1.5 text-2xl font-bold tabular-nums text-slate-900 dark:text-white">{{ $metrics['totals'][$card['key']] }}</p>
                </div>
            @endforeach
        </div>

        <div class="mb-5 grid gap-3 lg:grid-cols-3">
            {{-- Conversation state --}}
            <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-white/15 dark:bg-[#0d0f15]">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-sm font-bold text-slate-900 dark:text-white">Conversations</h2>
                    <span class="text-[11px] text-slate-500 dark:text-slate-400" title="Active = open, Completed = resolved, Escalated = waiting for a human.">Active · Completed · Escalated</span>
                </div>

                <div class="space-y-3">
                    @foreach ([
                        ['key' => 'active', 'label' => 'Active', 'value' => $metrics['totals']['active'], 'color' => 'bg-sky-500', 'text' => 'text-sky-600 dark:text-sky-300'],
                        ['key' => 'completed', 'label' => 'Completed', 'value' => $metrics['totals']['completed'], 'color' => 'bg-emerald-500', 'text' => 'text-emerald-600 dark:text-emerald-300'],
                        ['key' => 'escalated', 'label' => 'Escalated', 'value' => $metrics['totals']['escalated'], 'color' => 'bg-amber-500', 'text' => 'text-amber-600 dark:text-amber-300'],
                    ] as $row)
                        <div class="flex items-center gap-3">
                            <span class="h-2.5 w-2.5 rounded-full {{ $row['color'] }}" aria-hidden="true"></span>
                            <span class="flex-1 text-sm text-slate-700 dark:text-slate-200">{{ $row['label'] }}</span>
                            <span data-metric="totals.{{ $row['key'] }}" data-format="int"
                                  class="text-sm font-bold tabular-nums {{ $row['text'] }}">{{ $row['value'] }}</span>
                        </div>
                    @endforeach
                </div>

                <div class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-300">
                    <p><span class="font-semibold">{{ number_format($metrics['totals']['helpful']) }}</span> helpful ·
                       <span class="font-semibold">{{ number_format($metrics['totals']['not_helpful']) }}</span> not helpful ·
                       <span class="font-semibold">{{ $metrics['totals']['consent_rate'] }}%</span> email consent</p>
                </div>
            </div>

            {{-- Outcome split --}}
            <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-white/15 dark:bg-[#0d0f15]">
                <h2 class="mb-3 text-sm font-bold text-slate-900 dark:text-white">Response outcomes</h2>
                <div id="outcome-donut" class="flex items-center gap-4">
                    <div class="relative h-28 w-28 shrink-0">
                        <svg viewBox="0 0 42 42" class="h-full w-full -rotate-90" role="img" aria-label="Response outcome split">
                            <circle cx="21" cy="21" r="15.9155" fill="none" stroke="currentColor" class="text-slate-200 dark:text-white/10" stroke-width="5"></circle>
                            @php($offset = 0)
                            @foreach ($metrics['outcomes'] as $outcome)
                                @if ($outcome['value'] > 0)
                                    <circle cx="21" cy="21" r="15.9155" fill="none"
                                        data-outcome-segment="{{ $outcome['key'] }}"
                                        stroke="{{ ['successful' => '#10b981', 'fallback' => '#f59e0b', 'failed' => '#f43f5e', 'refused' => '#64748b', 'unanswered' => '#6366f1'][$outcome['key']] }}"
                                        stroke-width="5"
                                        stroke-dasharray="{{ $outcome['percent'] }} {{ 100 - $outcome['percent'] }}"
                                        stroke-dashoffset="{{ -$offset }}"
                                        stroke-linecap="butt">
                                        <title>{{ $outcome['label'] }}: {{ $outcome['value'] }} ({{ $outcome['percent'] }}%)</title>
                                    </circle>
                                    @php($offset += $outcome['percent'])
                                @endif
                            @endforeach
                        </svg>
                        <div class="pointer-events-none absolute inset-0 grid place-items-center text-center">
                            <div>
                                <p data-metric="totals.messages" data-format="int" class="text-lg font-bold tabular-nums text-slate-900 dark:text-white">{{ number_format($metrics['totals']['messages']) }}</p>
                                <p class="text-[10px] uppercase tracking-wide text-slate-500 dark:text-slate-400">replies</p>
                            </div>
                        </div>
                    </div>

                    <ul class="min-w-0 flex-1 space-y-1.5">
                        @foreach ($metrics['outcomes'] as $outcome)
                            <li class="flex items-center gap-2 text-xs" title="{{ $outcome['hint'] }}">
                                <span class="h-2 w-2 rounded-full" style="background: {{ ['successful' => '#10b981', 'fallback' => '#f59e0b', 'failed' => '#f43f5e', 'refused' => '#64748b', 'unanswered' => '#6366f1'][$outcome['key']] }}" aria-hidden="true"></span>
                                <span class="flex-1 truncate text-slate-700 dark:text-slate-200">{{ $outcome['label'] }}</span>
                                <span data-outcome-value="{{ $outcome['key'] }}" class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ $outcome['value'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>

            {{-- Failures --}}
            <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-white/15 dark:bg-[#0d0f15]">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h2 class="text-sm font-bold text-slate-900 dark:text-white">Failure reasons</h2>
                    <span class="rounded-full bg-rose-50 px-2 py-0.5 text-[11px] font-semibold text-rose-700 dark:bg-rose-500/15 dark:text-rose-300"
                          data-metric="totals.failed" data-format="int">{{ $metrics['totals']['failed'] }} failed</span>
                </div>

                <p data-failure-empty @if ($metrics['failures'] !== []) hidden @endif class="rounded-lg border border-dashed border-slate-300 p-4 text-center text-xs text-slate-500 dark:border-white/15 dark:text-slate-400">
                    No failed responses in this range.
                </p>
                <ul data-failure-list @if ($metrics['failures'] === []) hidden @endif class="space-y-3">
                    @foreach ($metrics['failures'] as $failure)
                        <li title="{{ $failure['hint'] }}">
                            <div class="flex items-baseline justify-between gap-2 text-xs">
                                <span class="font-semibold text-slate-800 dark:text-slate-100">{{ $failure['label'] }}</span>
                                <span class="tabular-nums text-slate-500 dark:text-slate-400">{{ $failure['value'] }}</span>
                            </div>
                            <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-white/10">
                                <span class="block h-full rounded-full bg-rose-500" style="width: {{ $metrics['totals']['failed'] > 0 ? max(6, round($failure['value'] / $metrics['totals']['failed'] * 100)) : 0 }}%"></span>
                            </div>
                            <p class="mt-1 text-[11px] leading-snug text-slate-500 dark:text-slate-400">{{ $failure['hint'] }}</p>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        <div class="mb-5 grid gap-3 lg:grid-cols-3">
            {{-- Activity chart --}}
            <div class="rounded-xl border border-slate-200 bg-white p-5 lg:col-span-2 dark:border-white/15 dark:bg-[#0d0f15]">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sm font-bold text-slate-900 dark:text-white">Message activity</h2>
                    <div class="flex items-center gap-3 text-[11px] text-slate-600 dark:text-slate-300">
                        <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-sm bg-emerald-500"></span>Answered</span>
                        <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-sm bg-amber-500"></span>Fallback</span>
                        <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-sm bg-rose-500"></span>Failed</span>
                    </div>
                </div>

                <div data-activity-chart class="flex h-44 items-end gap-1.5" role="img"
                     aria-label="Daily message volume for the selected range">
                    @php($chartMax = max(array_merge([1], array_column($metrics['daily']['days'], 'messages'))))
                    @foreach ($metrics['daily']['days'] as $day => $values)
                        <div data-day="{{ $day }}" class="group relative flex h-full flex-1 flex-col justify-end gap-0.5"
                             title="{{ $day }} — {{ $values['messages'] }} messages ({{ $values['successful'] }} answered, {{ $values['fallback'] }} fallback, {{ $values['failed'] }} failed)">
                            <span data-bar="failed" class="block w-full rounded-t-sm bg-rose-500 transition-all" style="height: {{ $values['messages'] > 0 ? max(2, round($values['failed'] / $chartMax * 100)) : 0 }}%"></span>
                            <span data-bar="fallback" class="block w-full bg-amber-500 transition-all" style="height: {{ $values['messages'] > 0 ? max(2, round($values['fallback'] / $chartMax * 100)) : 0 }}%"></span>
                            <span data-bar="successful" class="block w-full bg-emerald-500 transition-all" style="height: {{ $values['messages'] > 0 ? max(2, round($values['successful'] / $chartMax * 100)) : 0 }}%"></span>
                        </div>
                    @endforeach
                </div>

                <div class="mt-2 flex justify-between text-[10px] text-slate-500 dark:text-slate-400">
                    <span>{{ \Illuminate\Support\Carbon::parse(array_key_first($metrics['daily']['days']))->format('d M') }}</span>
                    <span>Response time · <span data-metric="totals.avg_response_ms" data-format="ms">{{ $metrics['totals']['avg_response_ms'] }}</span> avg</span>
                    <span>{{ \Illuminate\Support\Carbon::parse(array_key_last($metrics['daily']['days']))->format('d M') }}</span>
                </div>
            </div>

            {{-- Widget engagement --}}
            <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-white/15 dark:bg-[#0d0f15]">
                <h2 class="mb-3 text-sm font-bold text-slate-900 dark:text-white">Widget engagement</h2>

                <div class="mb-4">
                    <div class="flex items-baseline justify-between">
                        <span class="text-xs text-slate-600 dark:text-slate-300">Open rate</span>
                        <span data-metric="events.engagement_rate" data-format="pct" class="text-xl font-bold text-indigo-600 dark:text-indigo-300">{{ $metrics['events']['engagement_rate'] }}%</span>
                    </div>
                    <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-white/10">
                        <span data-metric-bar="events.engagement_rate" class="block h-full rounded-full bg-indigo-500 transition-all" style="width: {{ $metrics['events']['engagement_rate'] }}%"></span>
                    </div>
                </div>

                <dl class="grid grid-cols-2 gap-3">
                    @foreach ([
                        ['key' => 'loads', 'label' => 'Widget loads'],
                        ['key' => 'opens', 'label' => 'Launcher opened'],
                        ['key' => 'messages', 'label' => 'Messages sent'],
                        ['key' => 'unique_visitors', 'label' => 'Unique visitors'],
                        ['key' => 'suggestions', 'label' => 'Suggestion taps'],
                        ['key' => 'closes', 'label' => 'Launcher closed'],
                    ] as $row)
                        <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 dark:border-white/10 dark:bg-white/5">
                            <dt class="text-[11px] text-slate-500 dark:text-slate-400">{{ $row['label'] }}</dt>
                            <dd data-metric="events.{{ $row['key'] }}" data-format="int" class="text-lg font-bold tabular-nums text-slate-900 dark:text-white">{{ number_format($metrics['events'][$row['key']]) }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </div>

        <div class="mb-5 grid gap-3 lg:grid-cols-3">
            {{-- Knowledge usage --}}
            <div class="rounded-xl border border-slate-200 bg-white p-5 lg:col-span-2 dark:border-white/15 dark:bg-[#0d0f15]">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sm font-bold text-slate-900 dark:text-white">Knowledge sources used</h2>
                    <span class="text-[11px] text-slate-500 dark:text-slate-400"
                          title="How often each document contributed an answer in this range.">
                        {{ number_format($metrics['knowledge']['cache_hit']) }} source lookups
                    </span>
                </div>

                @if ($metrics['knowledge']['sources'] === [])
                    <p class="rounded-lg border border-dashed border-slate-300 p-6 text-center text-xs text-slate-500 dark:border-white/15 dark:text-slate-400">
                        No answers have used your knowledge yet. Upload documents in the Knowledge tab and link them in Widget settings.
                    </p>
                @else
                    <ul class="space-y-3">
                        @foreach ($metrics['knowledge']['sources'] as $source)
                            <li>
                                <div class="flex items-baseline justify-between gap-3 text-xs">
                                    <span class="truncate font-semibold text-slate-800 dark:text-slate-100" title="{{ $source['document'] }}">{{ $source['document'] }}</span>
                                    <span class="shrink-0 tabular-nums text-slate-500 dark:text-slate-400">{{ $source['uses'] }}× used</span>
                                </div>
                                <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-white/10">
                                    <span class="block h-full rounded-full bg-emerald-500" style="width: {{ $source['percent'] }}%"></span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- Document processing --}}
            <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-white/15 dark:bg-[#0d0f15]">
                <h2 class="mb-3 text-sm font-bold text-slate-900 dark:text-white">Document processing</h2>
                <ul class="space-y-2.5">
                    @foreach ($metrics['documents'] as $document)
                        <li class="flex items-center gap-3" title="{{ $document['hint'] }}">
                            <span class="h-2.5 w-2.5 rounded-full
                                @if ($document['status'] === 'processed') bg-emerald-500
                                @elseif ($document['status'] === 'failed') bg-rose-500
                                @elseif ($document['status'] === 'processing') bg-sky-500
                                @else bg-slate-400 @endif" aria-hidden="true"></span>
                            <span class="flex-1 text-sm text-slate-700 dark:text-slate-200">{{ $document['label'] }}</span>
                            <span class="text-sm font-bold tabular-nums text-slate-900 dark:text-white">{{ $document['count'] }}</span>
                        </li>
                    @endforeach
                </ul>
                <a href="{{ route('dashboard') }}"
                   class="btn btn-secondary btn-sm mt-4 inline-flex min-h-10 w-full items-center justify-center">
                    Open knowledge base
                </a>
            </div>
        </div>

        {{-- Latest answers (updates without a reload) --}}
        <div class="mb-5 rounded-xl border border-slate-200 bg-white p-5 dark:border-white/15 dark:bg-[#0d0f15]">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-bold text-slate-900 dark:text-white">Latest answers</h2>
                <span class="text-[11px] text-slate-500 dark:text-slate-400">Refreshes automatically every 30 seconds</span>
            </div>

            <div data-activity-feed class="space-y-2">
                @include('widget.partials.activity-feed', ['messages' => $metrics['recent'], 'site' => $site, 'canSeeLeads' => $canSeeLeads])
            </div>
        </div>

        {{-- Transcripts --}}
        <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-white/15 dark:bg-[#0d0f15]">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-sm font-bold text-slate-900 dark:text-white">Conversation transcripts</h2>
                <span class="text-[11px] text-slate-500 dark:text-slate-400">Search matches an exact email address or session ID.</span>
            </div>

            <form method="GET" action="{{ route('widget.analytics', $site) }}" class="mb-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
                @foreach (request()->query() as $key => $value)
                    @if ($key !== 'search' && $key !== 'status' && $key !== 'outcome' && $key !== 'sort' && $key !== 'page')
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                    @endif
                @endforeach

                <label class="sr-only" for="transcript-search">Search</label>
                <input id="transcript-search" name="search" value="{{ $filters['search'] }}" placeholder="Exact email or session ID"
                       class="min-w-0 rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 placeholder:text-slate-500 focus:border-indigo-600 focus:outline-none focus:ring-2 focus:ring-indigo-600/30 dark:border-white/20 dark:bg-white/5 dark:text-white dark:placeholder:text-slate-400">

                <select name="status" aria-label="Conversation status" class="rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 dark:border-white/20 dark:bg-white/5 dark:text-white">
                    <option value="">All statuses</option>
                    <option value="open" @selected($filters['status'] === 'open')>Open</option>
                    <option value="closed" @selected($filters['status'] === 'closed')>Closed</option>
                </select>

                <select name="outcome" aria-label="Outcome filter" class="rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 dark:border-white/20 dark:bg-white/5 dark:text-white">
                    <option value="">All outcomes</option>
                    <option value="escalated" @selected($filters['outcome'] === 'escalated')>Escalated</option>
                    <option value="resolved" @selected($filters['outcome'] === 'resolved')>Resolved</option>
                    <option value="failed" @selected($filters['outcome'] === 'failed')>Had failures</option>
                    <option value="fallback" @selected($filters['outcome'] === 'fallback')>Had fallbacks</option>
                </select>

                <select name="sort" aria-label="Sort transcripts" class="rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 dark:border-white/20 dark:bg-white/5 dark:text-white">
                    <option value="newest" @selected($filters['sort'] === 'newest')>Newest first</option>
                    <option value="oldest" @selected($filters['sort'] === 'oldest')>Oldest first</option>
                    <option value="escalated" @selected($filters['sort'] === 'escalated')>Escalated first</option>
                    <option value="messages" @selected($filters['sort'] === 'messages')>Most messages</option>
                </select>

                <button type="submit" class="btn btn-secondary min-h-11 w-full">Filter</button>
            </form>

            @if ($metrics['transcripts']->isEmpty())
                <div class="rounded-lg border border-dashed border-slate-300 p-10 text-center dark:border-white/15">
                    <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">No conversations match this range</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Widen the date range or clear the filters to see more.</p>
                    <a href="{{ route('widget.analytics', $site) }}" class="btn btn-ghost mt-4 inline-flex">Clear filters</a>
                </div>
            @else
                <ul class="space-y-2">
                    @foreach ($metrics['transcripts'] as $conversation)
                        <li class="rounded-xl border border-slate-200 bg-slate-50/60 dark:border-white/10 dark:bg-white/[0.03]">
                            <details class="group">
                                <summary class="flex cursor-pointer list-none flex-wrap items-center gap-3 px-4 py-3">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold
                                        @if ($conversation->status === 'closed') bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300
                                        @else bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300 @endif"
                                        title="{{ $conversation->status === 'closed' ? 'Completed' : 'Active' }}">
                                        #{{ $conversation->getKey() }}
                                    </span>

                                    <span class="min-w-0 flex-1">
                                        <span class="flex flex-wrap items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                                            <span class="font-semibold text-slate-900 dark:text-white">{{ $conversation->created_at->format('d M Y, H:i') }}</span>
                                            <span>· {{ $conversation->message_count }} messages</span>
                                            @if ($conversation->assignedUser)
                                                <span>· assigned to {{ $conversation->assignedUser->name }}</span>
                                            @endif
                                        </span>
                                        <span class="mt-0.5 block truncate text-xs text-slate-500 dark:text-slate-400">
                                            {{ $conversation->messages->first()?->role?->value === 'user' ? 'Visitor: '.\Illuminate\Support\Str::limit($conversation->messages->first()->content, 90) : 'Transcript' }}
                                        </span>
                                    </span>

                                    <span class="flex flex-wrap items-center gap-1.5">
                                        <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold
                                            @if ($conversation->classification === 'lead') bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300
                                            @else bg-slate-200 text-slate-700 dark:bg-white/10 dark:text-slate-300 @endif">{{ $conversation->classification }}</span>

                                        @if ($conversation->escalated_at)
                                            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800 dark:bg-amber-500/15 dark:text-amber-300"
                                                  title="Escalated {{ $conversation->escalated_at->diffForHumans() }}: {{ $conversation->escalation_reason }}">Escalated</span>
                                        @endif

                                        @if ($conversation->resolved_at)
                                            <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300"
                                                  title="Resolved {{ $conversation->resolved_at->diffForHumans() }}">Resolved</span>
                                        @endif

                                        @if ($canSeeLeads && $conversation->visitor_email)
                                            <span class="rounded-full bg-white px-2 py-0.5 text-[11px] font-semibold text-slate-600 ring-1 ring-slate-200 dark:bg-white/10 dark:text-slate-300 dark:ring-white/10"
                                                  title="Visitor email (consent recorded {{ $conversation->visitor_email_consent_at?->format('d M Y') }})">{{ $conversation->visitor_email }}</span>
                                        @endif
                                    </span>

                                    <svg class="h-4 w-4 shrink-0 text-slate-400 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                                </summary>

                                <div class="border-t border-slate-200 px-4 py-3 dark:border-white/10">
                                    <ol class="space-y-2">
                                        @foreach ($conversation->messages->sortBy('id') as $message)
                                            <li class="flex gap-3 text-xs">
                                                <span class="w-16 shrink-0 pt-0.5 text-right tabular-nums text-slate-400 dark:text-slate-500"
                                                      title="{{ $message->created_at->format('d M Y, H:i:s') }}">{{ $message->created_at->format('H:i') }}</span>
                                                <span class="flex-1 rounded-lg px-3 py-2
                                                    @if ($message->role->value === 'user') bg-slate-200/70 text-slate-800 dark:bg-white/10 dark:text-slate-100
                                                    @elseif ($message->status === \App\Enums\MessageStatus::Failed) bg-rose-50 text-rose-800 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-200 dark:ring-rose-400/20
                                                    @else bg-white text-slate-700 ring-1 ring-slate-200 dark:bg-white/5 dark:text-slate-200 dark:ring-white/10 @endif">
                                                    <span class="mb-1 block text-[10px] font-bold uppercase tracking-wide opacity-70">
                                                        {{ $message->role->value === 'user' ? 'Visitor' : ($site->bot_name ?: 'Assistant') }}
                                                        @if ($message->latency_ms !== null && $message->role->value !== 'user')
                                                            · {{ $message->latency_ms >= 1000 ? round($message->latency_ms / 1000, 1).'s' : $message->latency_ms.'ms' }}
                                                        @endif
                                                    </span>

                                                    <span class="whitespace-pre-wrap break-words">{{ \Illuminate\Support\Str::limit($message->content, 900) }}</span>

                                                    @if ($message->status === \App\Enums\MessageStatus::Failed)
                                                        <span class="mt-2 block rounded-md bg-rose-100/70 px-2 py-1 text-[11px] font-semibold text-rose-800 dark:bg-rose-500/15 dark:text-rose-200"
                                                              title="This message failed and was escalated to your team.">
                                                            Failed · {{ \App\Http\Controllers\Widget\WidgetAnalyticsController::failureLabel($message->error_reason) }}
                                                        </span>
                                                    @endif

                                                    @if ($message->was_fallback)
                                                        <span class="mt-2 block text-[11px] font-semibold text-amber-700 dark:text-amber-300">Fallback answer — no knowledge matched</span>
                                                    @endif

                                                    @if ($message->sources !== [] && $message->sources !== null)
                                                        <span class="mt-2 flex flex-wrap gap-1.5">
                                                            @foreach ($message->sourceBadges() as $source)
                                                                @if (! empty($source['document']))
                                                                    <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300"
                                                                          title="Page {{ $source['page_from'] ?? '?' }}–{{ $source['page_to'] ?? '?' }} · relevance {{ round(($source['score'] ?? 0) * 100) }}%">
                                                                        {{ $source['document'] }} · p{{ $source['page_from'] ?? '?' }}
                                                                    </span>
                                                                @endif
                                                            @endforeach
                                                        </span>
                                                    @endif
                                                </span>
                                            </li>
                                        @endforeach
                                    </ol>

                                    @if ($conversation->status === 'open')
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            @if (! $conversation->escalated_at)
                                                <form method="POST" action="{{ route('widget.leads.escalate', ['site' => $site, 'conversation' => $conversation]) }}">
                                                    @csrf
                                                    <input type="hidden" name="reason" value="manual">
                                                    <button type="submit" class="btn btn-secondary btn-sm">Escalate to a human</button>
                                                </form>
                                            @endif
                                            <form method="POST" action="{{ route('widget.leads.resolve', ['site' => $site, 'conversation' => $conversation]) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-primary btn-sm">Mark resolved</button>
                                            </form>
                                            <a href="{{ route('widget.leads.index', $site) }}" class="btn btn-ghost btn-sm">Open in inbox</a>
                                        </div>
                                    @endif
                                </div>
                            </details>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-4">{{ $metrics['transcripts']->links() }}</div>
            @endif
        </div>
    </section>
@endsection
