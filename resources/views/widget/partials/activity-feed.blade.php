<div class="divide-y divide-slate-200 dark:divide-white/10">
    @forelse ($messages as $message)
        @php($tone = match (true) {
            $message['status'] === 'failed' => ['bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300', 'Failed'],
            $message['was_refused'] => ['bg-slate-200 text-slate-700 dark:bg-white/10 dark:text-slate-300', 'Declined'],
            $message['was_fallback'] => ['bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300', 'Fallback'],
            default => ['bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300', 'Answered'],
        })
        <div class="flex flex-wrap items-start gap-3 py-2.5" data-activity-row>
            <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $tone[0] }}" title="{{ $message['was_fallback'] ? 'No knowledge matched — the bot answered generally.' : ($message['status'] === 'failed' ? \App\Http\Controllers\Widget\WidgetAnalyticsController::failureLabel($message['error_reason']) : 'Answered from your knowledge.') }}">
                {{ $tone[1] }}
            </span>

            <span class="min-w-0 flex-1">
                <span class="block truncate text-xs text-slate-700 dark:text-slate-200">{{ $message['preview'] ?: 'Empty response' }}</span>
                <span class="mt-0.5 flex flex-wrap items-center gap-1.5 text-[11px] text-slate-500 dark:text-slate-400">
                    <span title="{{ \Illuminate\Support\Carbon::parse($message['created_at'])->format('d M Y, H:i:s') }}">{{ $message['asked_at'] }}</span>
                    @if ($message['latency_ms'] !== null)
                        <span>· {{ $message['latency_ms'] >= 1000 ? round($message['latency_ms'] / 1000, 1).'s' : $message['latency_ms'].'ms' }}</span>
                    @endif
                    @if ($message['escalated'])
                        <span class="font-semibold text-amber-700 dark:text-amber-300">· escalated</span>
                    @endif
                    @foreach (array_slice($message['sources'], 0, 3) as $source)
                        <span class="rounded-full bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300" title="Knowledge source used">{{ $source }}</span>
                    @endforeach
                </span>
            </span>

            @if ($message['status'] === 'failed')
                <a href="{{ route('widget.leads.index', ['site' => $site, 'status' => 'open']) }}"
                   class="btn btn-secondary btn-sm"
                   title="Review the conversation and retry the question with your team">Resolve</a>
            @endif
        </div>
    @empty
        <div class="rounded-lg border border-dashed border-slate-300 p-8 text-center text-xs text-slate-500 dark:border-white/15 dark:text-slate-400">
            No answers yet in this range. Responses appear here the moment the assistant replies.
        </div>
    @endforelse
</div>
