@php
    use App\Enums\ChatRole;
    use App\Enums\MessageStatus;
@endphp

@php($isUser = $message->role === ChatRole::User)

<article
    data-message
    data-role="{{ $message->role->value }}"
    data-message-id="{{ $message->getKey() }}"
    class="group {{ $isUser ? 'flex justify-end' : '' }}"
>
    @if ($isUser)
        <div class="max-w-[85%] sm:max-w-[80%]">
            <div
                data-message-content
                class="whitespace-pre-wrap break-words rounded-3xl rounded-tr-md bg-blue-100 px-4 py-2.5 text-[15px] leading-relaxed text-slate-800 shadow-xs dark:bg-blue-500/15 dark:text-slate-700 dark:ring-1 dark:ring-blue-400/20"
            >{{ $message->content }}</div>

            <div class="dm-action-bar mt-1 flex items-center justify-end gap-1 group-hover:opacity-100 group-focus-within:opacity-100">
                <button
                    type="button"
                    data-copy-user-message
                    class="dm-icon-button rounded-lg p-1.5 text-slate-400 dark:text-slate-500 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-white/10 dark:hover:text-slate-200"
                    title="Copy prompt"
                    aria-label="Copy prompt"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect width="14" height="14" x="8" y="8" rx="2" ry="2"/>
                        <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>
                    </svg>
                </button>
            </div>
        </div>
    @else
        @php($sources = $message->sourceBadges())
        <div class="w-full max-w-3xl">
            <div class="flex items-start gap-3">
                {{-- Brand mark: pulses with a halo while the answer streams --}}
                <x-blobatar :name="config('app.name')" :size="28" data-blobatar-follow="true" class="dm-avatar mt-0.5 ring-1 ring-white/20" />

                <div class="min-w-0 flex-1">
                    {{-- Reasoning accordion --}}
                    @if ($message->latency_ms || $sources !== [])
                        <details class="grok-thought group/thought">
                            <summary class="dm-thinking-pill">
                                <svg class="grok-thought-icon shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                                </svg>
                                <span>
                                    @if ($message->latency_ms)
                                        Thought for {{ round($message->latency_ms / 1000, 1) }}s
                                    @else
                                        Thinking process
                                    @endif
                                    @if ($sources !== [])
                                        &middot; {{ count($sources) }} {{ \Illuminate\Support\Str::plural('support source', count($sources)) }}
                                    @endif
                                </span>
                            </summary>
                            <div class="grok-thought-body">
                                <p class="text-xs text-slate-500">
                                    @if ($sources !== [])
                                        Retrieved relevant support knowledge; open a source item to verify the matched excerpt.
                                    @else
                                        No matching support knowledge was available. Ask for clarification or escalate to the support team.
                                    @endif
                                </p>
                            </div>
                        </details>
                    @endif

                    <div
                        data-message-content
                        class="grok-prose text-[15px] leading-relaxed text-slate-700 {{ $message->status === MessageStatus::Failed
                            ? 'rounded-2xl border border-amber-300/60 bg-amber-50/80 px-4 py-3 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300'
                            : '' }}"
                    >{{ $message->content !== '' ? $message->content : '…' }}</div>

                    @if ($message->status === MessageStatus::Failed && $message->content === '')
                        <p class="mt-2 text-xs text-amber-700 dark:text-amber-300">The answer could not be generated. Your credit was refunded.</p>
                    @endif
                </div>
            </div>

            {{-- Source Citation Badges --}}
            @if ($sources !== [])
                <div class="mt-3 flex flex-wrap items-center gap-1.5 pl-10" data-sources>
                    <span class="mr-1 text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">Support knowledge</span>
                    @foreach ($message->citationRows() as $citation)
                        <details class="group/source max-w-full">
                            <summary class="inline-flex cursor-pointer list-none items-center gap-1.5 rounded-full border border-slate-200 bg-slate-50/80 px-2.5 py-1 text-[11px] font-medium text-slate-600 transition hover:border-indigo-300 hover:bg-indigo-50/60 hover:text-indigo-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-600 dark:hover:border-indigo-400 dark:hover:bg-indigo-500/10 dark:hover:text-indigo-300">
                                <span class="flex h-3.5 w-3.5 items-center justify-center rounded-full bg-indigo-100 text-[9px] font-bold text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300">
                                    {{ $citation['position'] }}
                                </span>
                                <span class="truncate">
                                    @if ($citation['keyword'])
                                        Direct match
                                    @else
                                        {{ (int) round($citation['score'] * 100) }}% support match
                                    @endif
                                </span>
                            </summary>

                            <div class="mt-2 w-80 max-w-[85vw] rounded-2xl border border-slate-200 bg-white dark:bg-[#0d0f15] p-4 text-xs leading-relaxed text-slate-600 shadow-xl dark:border-white/10 dark:text-slate-600">
                                <div class="mb-2 flex items-center justify-between border-b border-slate-100 pb-2 dark:border-white/10">
                                    <span class="text-[10px] font-bold uppercase tracking-wider text-indigo-600 dark:text-indigo-400">
                                        Knowledge match [{{ $citation['position'] }}]
                                    </span>
                                </div>
                                <blockquote class="italic text-slate-700 dark:text-slate-700">
                                    "{{ $citation['snippet'] !== '' ? $citation['snippet'] : 'No preview available.' }}"
                                </blockquote>
                            </div>
                        </details>
                    @endforeach
                </div>
            @endif

            {{-- Answer Action Bar --}}
            @if ($message->status === MessageStatus::Complete && $message->content !== '')
                <div class="dm-action-bar mt-2 flex flex-wrap items-center gap-0.5 pl-10 group-hover:opacity-100 group-focus-within:opacity-100">
                    {{-- Copy answer --}}
                    <button
                        type="button"
                        data-copy-answer
                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:text-slate-500 dark:hover:bg-white/10 dark:hover:text-white"
                        title="Copy answer"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect width="14" height="14" x="8" y="8" rx="2" ry="2"/>
                            <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>
                        </svg>
                        <span class="hidden sm:inline">Copy</span>
                    </button>

                    {{-- Text to Speech (Read Aloud) --}}
                    <button
                        type="button"
                        data-tts-button
                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:text-slate-500 dark:hover:bg-white/10 dark:hover:text-white"
                        title="Read aloud"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/>
                            <path d="M15.54 8.46a5 5 0 0 1 0 7.07"/>
                            <path d="M19.07 4.93a10 10 0 0 1 0 14.14"/>
                        </svg>
                        <span class="hidden sm:inline">Read</span>
                    </button>

                    {{-- Thumbs Up --}}
                    <button
                        type="button"
                        data-feedback-thumb="up"
                        data-state="{{ $message->was_helpful === null ? 'null' : ($message->was_helpful ? 'true' : 'false') }}"
                        @class([
                            'dm-icon-button rounded-full p-1.5 hover:bg-slate-100 dark:hover:bg-white/10',
                            'text-emerald-600 dark:text-emerald-400 font-bold' => $message->was_helpful === true,
                            'text-slate-400 dark:text-slate-500 hover:text-emerald-600 dark:hover:text-emerald-400' => $message->was_helpful !== true,
                        ])
                        title="Good response"
                        aria-label="Good response"
                        aria-pressed="{{ $message->was_helpful === true ? 'true' : 'false' }}"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M7 10v12"/>
                            <path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h3"/>
                        </svg>
                    </button>

                    {{-- Thumbs Down --}}
                    <button
                        type="button"
                        data-feedback-thumb="down"
                        data-state="{{ $message->was_helpful === null ? 'null' : ($message->was_helpful ? 'true' : 'false') }}"
                        @class([
                            'dm-icon-button rounded-full p-1.5 hover:bg-slate-100 dark:hover:bg-white/10',
                            'text-rose-600 dark:text-rose-400 font-bold' => $message->was_helpful === false,
                            'text-slate-400 dark:text-slate-500 hover:text-rose-600 dark:hover:text-rose-400' => $message->was_helpful !== false,
                        ])
                        title="Bad response"
                        aria-label="Bad response"
                        aria-pressed="{{ $message->was_helpful === false ? 'true' : 'false' }}"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M17 14V2"/>
                            <path d="M9 18.12 10 14H4.17a2 2 0 0 1-1.92-2.56l2.33-8A2 2 0 0 1 6.5 2H20a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-3"/>
                        </svg>
                    </button>

                    @if ($message->model_used || $message->latency_ms)
                        <span class="ml-auto pr-1 text-[11px] text-slate-400 dark:text-slate-500" title="Answered by {{ \App\Support\ModelBrand::name($message->model_used) }}">
                            {{ \App\Support\ModelBrand::label($message->model_used, $message->latency_ms) }}
                        </span>
                    @endif
                </div>
            @endif
        </div>
    @endif
</article>