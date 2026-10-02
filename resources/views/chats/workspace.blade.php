@extends('layouts.app')

@section('title', $chat?->title ?? 'Chat · '.config('app.name'))

@section('content')
    {{-- Full-height chat shell that escapes <main>'s padding and max width. --}}
    <div class="h-full" data-chat-root>
        <div class="flex h-full min-h-0 flex-1 overflow-hidden bg-slate-50 dark:bg-black">
            {{-- Grok-style Collapsible Sidebar --}}
            <aside
                data-chat-sidebar
                data-open="true"
                class="dm-sidebar flex w-72 shrink-0 flex-col border-r border-slate-200 bg-white transition-transform duration-200 dark:border-white/10 dark:bg-[#0d0f15] max-lg:absolute max-lg:inset-y-0 max-lg:left-0 max-lg:z-40 max-lg:w-80 max-lg:shadow-xl max-lg:data-[open=false]:-translate-x-full"
            >
                {{-- Top Actions --}}
                <div class="flex items-center gap-2 border-b border-slate-200/80 p-3.5 dark:border-white/10">
                    <a
                        href="{{ route('dashboard') }}"
                        class="flex min-w-0 items-center gap-2 rounded-xl px-2.5 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/10"
                        title="Manage support knowledge"
                    >
                        <svg class="h-4 w-4 text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/>
                            <polyline points="14 2 14 8 20 8"/>
                        </svg>
                        <span class="truncate">Knowledge base</span>
                    </a>

                    <div class="flex-1"></div>

                    @if ($documents->isNotEmpty())
                        <form method="POST" action="{{ route('chats.store') }}" class="inline-flex">
                            @csrf
                            <input type="hidden" name="document_id" value="{{ $chat?->document_id ?? $documents->first()->getKey() }}">
                            <button
                                type="submit"
                                class="btn btn-primary btn-sm"
                                title="Start a new chat"
                            >
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                    <path d="M12 5v14M5 12h14" />
                                </svg>
                                <span>New chat</span>
                            </button>
                        </form>
                    @endif

                    <button
                        type="button"
                        data-sidebar-toggle
                        aria-label="Toggle sidebar"
                        class="btn btn-ghost btn-icon btn-sm lg:hidden"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M18 6 6 18M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                {{-- Live Search Filter --}}
                <div class="px-3 pt-3.5">
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-3 top-2.5 h-3.5 w-3.5 text-slate-400 dark:text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"/>
                            <path d="m21 21-4.3-4.3"/>
                        </svg>
                        <input
                            type="search"
                            data-search-chats
                            aria-label="Search conversations"
                            placeholder="Find a conversation"
                            class="dm-sidebar-search h-10 w-full rounded-xl border border-slate-200/80 bg-white/80 pl-9 pr-3 text-xs text-slate-900 placeholder:text-slate-400 shadow-sm dark:placeholder:text-slate-500 focus:bg-white dark:focus:bg-white/10 focus:outline-none dark:border-white/10 dark:bg-white/5 dark:text-white"
                        >

                    </div>
                </div>

                {{-- Conversations & Knowledge Sources --}}
                <div class="min-h-0 flex-1 space-y-5 overflow-y-auto p-3" data-chat-list-container>
                    <section>
                        <div class="flex items-center justify-between px-2 text-[10px] font-bold uppercase tracking-[0.16em] text-slate-400 dark:text-slate-500">
                            <span>Recent conversations</span>
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold tracking-normal text-slate-500 dark:bg-white/5 dark:text-slate-400">{{ count($chats) }}</span>
                        </div>

                        <ul class="mt-2 space-y-0.5" data-chats-list>
                            @forelse ($chats as $history)
                                <li
                                    data-chat-item
                                    data-chat-title="{{ strtolower($history->title) }}"
                                    @class([
                                        'dm-chat-row',
                                        'data-active' => $chat?->is($history),
                                    ])
                                    style="--dm-row-index: {{ $loop->index }};"
                                >
                                    <div class="group flex items-center justify-between gap-1 rounded-xl px-2.5 py-2 text-sm {{ $chat?->is($history)
                                        ? 'bg-indigo-50 font-semibold text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-200'
                                        : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-500 dark:hover:bg-white/5 dark:hover:text-white' }}">

                                        <a
                                            href="{{ route('chats.show', $history) }}"
                                            class="flex min-w-0 flex-1 items-center gap-2.5"
                                            title="{{ $history->title }}"
                                        >
                                            <x-blobatar :name="'chat-'.$history->getKey()" :fallback="$history->title" :size="20" background="squircle" data-blobatar-follow="true" class="ring-1 ring-slate-200 dark:ring-white/10" />
                                            <span class="truncate text-xs" data-chat-item-title>{{ $history->title }}</span>
                                        </a>

                                        <div class="flex items-center gap-0.5 opacity-100 transition-opacity sm:opacity-0 sm:group-hover:opacity-100 sm:group-focus-within:opacity-100">
                                            {{-- Inline Rename --}}
                                            <button
                                                type="button"
                                                data-rename-chat-trigger
                                                data-chat-id="{{ $history->getKey() }}"
                                                data-current-title="{{ $history->title }}"
                                                data-update-url="{{ route('chats.update', $history) }}"
                                                class="dm-icon-button rounded-md p-1.5 text-slate-400 dark:text-slate-500 hover:text-slate-700 dark:hover:text-slate-200"
                                                title="Rename chat"
                                                aria-label="Rename chat"
                                            >
                                                <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>
                                                </svg>
                                            </button>

                                            {{-- Delete Chat --}}
                                            <form method="POST" action="{{ route('chats.destroy', $history) }}" data-delete-chat class="inline">
                                                @csrf
                                                @method('DELETE')
                                                <button
                                                    type="submit"
                                                    class="dm-icon-button rounded-md p-1.5 text-slate-400 dark:text-slate-500 hover:text-rose-600 dark:hover:text-rose-400"
                                                    title="Delete chat"
                                                    aria-label="Delete chat"
                                                >
                                                    <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M3 6h18m-2 0v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6m3 0V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2" />
                                                    </svg>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </li>
                            @empty
                                <li class="px-2.5 py-3 text-xs text-slate-400 dark:text-slate-500">
                                    No support conversations yet. Select a knowledge source to test your assistant.
                                </li>
                            @endforelse
                        </ul>

                        <p
                            data-chat-search-empty
                            class="hidden px-2.5 py-3 text-xs text-slate-400 dark:text-slate-500"
                        >
                            No conversations match your search.
                        </p>
                    </section>

                    {{-- Knowledge section in sidebar --}}
                    <section>
                        <div class="flex items-center justify-between gap-2 px-2">
                            <div class="flex min-w-0 items-center gap-2">
                                <h2 class="text-xs font-semibold text-slate-800 dark:text-slate-200">Knowledge sources</h2>
                                <span class="rounded-md bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-slate-500 dark:bg-white/[0.06] dark:text-slate-400">{{ $documents->count() }}</span>
                            </div>
                            <a href="{{ route('dashboard') }}" class="inline-flex min-h-8 shrink-0 items-center gap-1 rounded-lg px-2 text-[11px] font-semibold text-slate-600 transition hover:bg-slate-100 hover:text-indigo-700 dark:text-slate-300 dark:hover:bg-white/[0.06] dark:hover:text-white">
                                Manage
                                <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path d="M7 17 17 7M7 7h10v10"/>
                                </svg>
                            </a>
                        </div>

                        <ul class="mt-2 space-y-1">
                            @forelse ($documents as $document)
                                <li>
                                    <form method="POST" action="{{ route('chats.store') }}">
                                        @csrf
                                        <input type="hidden" name="document_id" value="{{ $document->getKey() }}">

                                        <button
                                            type="submit"
                                            aria-label="Start a chat using {{ $document->filename }}"
                                            title="{{ $document->filename }}"
                                            @class([
                                                'dm-doc-row group flex min-h-14 w-full items-start gap-2.5 rounded-lg px-2.5 py-2 text-left transition',
                                                'bg-indigo-50/70 dark:bg-indigo-500/[0.08]' => $chat?->document_id === $document->getKey(),
                                                'hover:bg-slate-100/80 dark:hover:bg-white/[0.05]' => $chat?->document_id !== $document->getKey(),
                                            ])
                                        >
                                            <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-slate-200/80 bg-white text-slate-500 dark:border-white/10 dark:bg-white/[0.04] dark:text-slate-300">
                                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                                    <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/>
                                                    <path d="M14 2v6h6"/>
                                                </svg>
                                            </span>
                                            <span class="min-w-0 flex-1 pt-0.5">
                                                <span class="block break-words text-[13px] font-medium leading-[1.45] text-slate-800 [overflow-wrap:anywhere] dark:text-slate-200">{{ $document->filename }}</span>
                                                <span class="mt-1 block text-[10px] leading-4 text-slate-500 dark:text-slate-400">PDF <span aria-hidden="true">·</span> {{ number_format($document->page_count) }} pages <span aria-hidden="true">·</span> {{ number_format($document->chunk_count) }} sections</span>
                                            </span>
                                        </button>
                                    </form>
                                </li>
                            @empty
                                <li class="rounded-xl border border-dashed border-slate-200 p-2.5 text-center text-xs text-slate-400 dark:text-slate-500 dark:border-white/10">
                                    No knowledge sources yet.
                                </li>
                            @endforelse
                        </ul>
                    </section>
                </div>

                {{-- Sidebar Footer with Credits --}}
                <div class="border-t border-slate-200/80 p-3 dark:border-white/10">
                    <a href="{{ route('billing.index') }}" class="flex items-center justify-between gap-2 rounded-xl bg-white/85 px-3 py-2.5 text-xs text-slate-600 ring-1 ring-slate-200/80 transition hover:bg-white hover:ring-indigo-200 dark:bg-white/5 dark:text-slate-300 dark:ring-white/10 dark:hover:bg-white/[0.08] dark:hover:ring-indigo-400/30" title="Add credits">
                        <span class="flex items-center gap-1.5">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            {{ $cost }} credit/msg
                        </span>
                        <span class="font-bold text-slate-900 dark:text-white" data-sidebar-credits>
                            {{ $user->credits }} left
                        </span>
                    </a>
                    <p class="mt-2.5 text-center text-[10px] text-slate-400 dark:text-slate-500">Developed by <span class="font-medium text-slate-600 dark:text-slate-300">Xylotech</span></p>
                </div>
            </aside>

            {{-- Main Chat Area --}}
            <section class="dm-chat-shell relative flex min-w-0 flex-1 flex-col bg-white dark:bg-black">
                @if ($chat === null)
                    {{-- Empty state when no chat is selected --}}
                    <div class="flex flex-1 items-center justify-center px-6 py-12">
                        <div class="w-full max-w-xl text-center">
                            {{-- Hero mark with ambient glow --}}
                            <div class="relative mx-auto flex h-14 w-14 items-center justify-center rounded-xl border border-slate-200 bg-slate-100 text-slate-600 dark:border-white/10 dark:bg-white/[0.05] dark:text-slate-300">
                                    <svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M4 4h7v7H4z" />
                                        <path d="M13 13h7v7h-7z" />
                                        <path d="m4 20 16-16" />
                                    </svg>
                            </div>

                            <h1 class="mt-5 text-2xl font-bold text-slate-900 dark:text-white sm:text-3xl">
                                Test your support assistant
                            </h1>
                            <p class="mx-auto mt-3 max-w-md text-sm leading-relaxed text-slate-500">
                                Try customer questions about product features, setup, troubleshooting, pricing, or policies. The assistant will ask for support when it cannot confirm an answer.
                            </p>

                            @if ($documents->isNotEmpty())
                                <div class="mt-9 flex flex-col items-center gap-4">
                                    <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">
                                        Choose a support knowledge source to test
                                    </span>
                                    <div class="flex flex-wrap justify-center gap-2">
                                        @foreach ($documents as $document)
                                            <form method="POST" action="{{ route('chats.store') }}">
                                                @csrf
                                                <input type="hidden" name="document_id" value="{{ $document->getKey() }}">
                                                <button
                                                    type="submit"
                                                    class="dm-chip inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 shadow-xs hover:border-indigo-300 hover:bg-indigo-50/60 hover:text-indigo-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-700 dark:hover:border-indigo-400 dark:hover:bg-indigo-500/10 dark:hover:text-indigo-300"
                                                >
                                                    <svg class="h-3.5 w-3.5 text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/>
                                                        <polyline points="14 2 14 8 20 8"/>
                                                    </svg>
                                                    <span>{{ $document->filename }}</span>
                                                </button>
                                            </form>
                                        @endforeach
                                    </div>
                                </div>
                            @else
                                <a
                                    href="{{ route('dashboard') }}"
                                    class="btn btn-primary mt-9 inline-flex items-center gap-2"
                                >
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M12 5v14M5 12h14" />
                                    </svg>
                                    Add product knowledge
                                </a>
                            @endif
                        </div>
                    </div>
                @else
                    {{-- Chat Header --}}
                    <header class="flex h-16 shrink-0 items-center justify-between border-b border-slate-200 bg-white px-4 sm:px-6 dark:border-white/10 dark:bg-[#0d0f15]">
                        <div class="flex min-w-0 items-center gap-3">
                            <button
                                type="button"
                                data-sidebar-toggle
                                aria-label="Toggle sidebar"
                                class="dm-icon-button rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-500 dark:hover:bg-white/10 dark:hover:text-white lg:hidden"
                            >
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="3" x2="21" y1="6" y2="6"/>
                                    <line x1="3" x2="21" y1="12" y2="12"/>
                                    <line x1="3" x2="21" y1="18" y2="18"/>
                                </svg>
                            </button>

                            <div class="min-w-0">
                                <div class="flex items-center gap-1.5">
                                    <h1 class="truncate text-sm font-semibold text-slate-900 dark:text-white" data-header-title>
                                        {{ $chat->title }}
                                    </h1>
                                    <button
                                        type="button"
                                        data-rename-chat-trigger
                                        data-chat-id="{{ $chat->getKey() }}"
                                        data-current-title="{{ $chat->title }}"
                                        data-update-url="{{ route('chats.update', $chat) }}"
                                        class="dm-icon-button rounded p-1 text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-200"
                                        title="Rename chat"
                                        aria-label="Rename chat"
                                    >
                                        <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>
                                        </svg>
                                    </button>
                                </div>
                                <p class="flex items-center gap-1.5 truncate text-[11px] text-slate-500">
                                    <span class="h-2 w-2 shrink-0 rounded-full bg-emerald-500"></span>
                                    <span class="truncate">
                                        Knowledge: <span class="font-medium text-slate-700 dark:text-slate-300">{{ $chat->document?->filename ?? 'Support source' }}</span>
                                    </span>
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            {{-- Model / retrieval mode indicator --}}
                            <span class="hidden items-center gap-1.5 rounded-md border border-slate-200 bg-slate-50 px-2 py-1 text-[11px] font-medium text-slate-600 sm:inline-flex dark:border-white/10 dark:bg-white/[0.04] dark:text-slate-300">
                                        <svg class="h-3 w-3 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="m10 15 5-3-5-3v6Z"/>
                                </svg>
                                Support assistant
                            </span>

                                    {{-- Chat settings --}}
                                    <button
                                        type="button"
                                        data-chat-settings
                                        aria-label="Chat settings"
                                        title="Chat settings"
                                        class="dm-icon-button rounded-full p-2 text-slate-400 dark:text-slate-500 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-white/10 dark:hover:text-white"
                                    >
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <circle cx="12" cy="12" r="3"/>
                                            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6h.09A1.65 1.65 0 0 0 10 3.09V3a2 2 0 1 1 4 0v.09A1.65 1.65 0 0 0 15 4.6a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9v.09a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                                        </svg>
                                    </button>


                            {{-- Delete Chat --}}
                            <form method="POST" action="{{ route('chats.destroy', $chat) }}" data-delete-chat>
                                @csrf
                                @method('DELETE')
                                <button
                                    type="submit"
                                    aria-label="Delete chat"
                                    class="dm-icon-button rounded-full p-2 text-slate-400 dark:text-slate-500 hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-500/10 dark:hover:text-rose-400"
                                    title="Delete chat"
                                >
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M3 6h18m-2 0v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6m3 0V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2" />
                                    </svg>
                                </button>
                            </form>
                        </div>
                    </header>

                    {{-- Transcript Stream --}}
                    <div
                        data-transcript
                        class="dm-chat-surface min-h-0 flex-1 overflow-y-auto px-4 py-6 sm:px-6"
                    >
                        <div class="mx-auto flex w-full max-w-3xl flex-col gap-8 pb-48 pt-2 sm:gap-9">
                            @forelse ($messages as $message)
                                @include('chats.message', ['message' => $message])
                            @empty
                                {{-- Welcome prompt with pill suggestion chips --}}
                                <div data-placeholder class="pt-6 text-center sm:pt-12">
                                    <div class="relative mx-auto flex h-12 w-12 items-center justify-center rounded-xl border border-slate-200 bg-slate-100 text-slate-600 dark:border-white/10 dark:bg-white/[0.05] dark:text-slate-300">
                                            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M4 4h7v7H4z" />
                                                <path d="M13 13h7v7h-7z" />
                                                <path d="m4 20 16-16" />
                                            </svg>
                                    </div>

                                    <h2 class="mt-5 text-xl font-semibold text-slate-900 dark:text-white sm:text-2xl">
                                        Try a customer question
                                    </h2>
                                    <p class="mx-auto mt-1.5 max-w-md text-sm leading-relaxed text-slate-500">
                                        Test the assistant against your product knowledge before customers see it.
                                    </p>

                                    @php($suggestions = $chat->document?->suggestedQuestions() ?? [])
                                    @php($defaultSuggestions = [
                                        'How do I get started?',
                                        'What features are included?',
                                        'How can I troubleshoot a common issue?',
                                        'Where can I find pricing and plan details?',
                                    ])
                                    @php($mergedSuggestions = array_unique(array_merge($suggestions, $defaultSuggestions)))

                                    <div class="mx-auto mt-7 flex max-w-2xl flex-wrap justify-center gap-2">
                                        @foreach (array_slice($mergedSuggestions, 0, 4) as $suggestion)
                                            <button
                                                type="button"
                                                data-suggestion="{{ $suggestion }}"
                                                class="dm-chip group inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2 text-xs font-medium text-slate-600 shadow-xs hover:border-indigo-300 hover:bg-indigo-50/60 hover:text-indigo-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-600 dark:hover:border-indigo-400 dark:hover:bg-indigo-500/10 dark:hover:text-indigo-300"
                                            >
                                                <svg class="h-3.5 w-3.5 shrink-0 text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M9.5 3v18M14.5 3v18M3 9.5h18M3 14.5h18"/>
                                                </svg>
                                                <span>{{ $suggestion }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endforelse
                        </div>
                    </div>

                    {{-- Chat settings panel --}}
                    <div
                        data-settings-panel
                        class="pointer-events-none absolute right-3 top-3 z-20 w-72 origin-top-right scale-95 rounded-xl border border-slate-200 bg-white p-4 opacity-0 shadow-lg transition duration-200 dark:border-white/10 dark:bg-[#0d0f15] sm:right-5"
                        role="dialog"
                        aria-label="Chat settings"
                        hidden
                    >
                        <div class="flex items-center justify-between">
                            <h2 class="text-xs font-bold text-slate-900 dark:text-white">Chat settings</h2>
                            <button
                                type="button"
                                data-settings-close
                                aria-label="Close settings"
                                class="dm-icon-button rounded-lg p-1 text-slate-400 dark:text-slate-500 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-white/10 dark:hover:text-white"
                            >
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                                    <path d="M6 18 18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>

                        <dl class="mt-3.5 space-y-2.5 text-[11px]">
                            <div class="flex items-baseline justify-between gap-3">
                                        <dt class="shrink-0 text-slate-500">Knowledge source</dt>
                                <dd class="min-w-0 truncate text-right font-medium text-slate-800 dark:text-slate-700" title="{{ $chat->document?->filename }}">
                                    {{ $chat->document?->filename ?? 'None' }}
                                </dd>
                            </div>
                            <div class="flex items-baseline justify-between gap-3">
                                <dt class="shrink-0 text-slate-500">Knowledge sections</dt>
                                <dd class="font-medium text-slate-800 dark:text-slate-700">
                                    {{ number_format($chat->document?->chunk_count ?? 0) }}
                                </dd>
                            </div>
                            <div class="flex items-baseline justify-between gap-3">
                                <dt class="shrink-0 text-slate-500">Messages</dt>
                                <dd class="font-medium text-slate-800 dark:text-slate-700">{{ $chat->message_count }}</dd>
                            </div>
                            <div class="flex items-baseline justify-between gap-3">
                                <dt class="shrink-0 text-slate-500">Cost per message</dt>
                                <dd class="font-medium text-slate-800 dark:text-slate-700">
                                    {{ $cost }} {{ \Illuminate\Support\Str::plural('credit', $cost) }}
                                </dd>
                            </div>
                            <div class="flex items-baseline justify-between gap-3">
                                <dt class="shrink-0 text-slate-500">Model</dt>
                                <dd class="flex items-center gap-1.5 font-medium text-slate-800 dark:text-slate-700">
                                    <span class="h-1.5 w-1.5 rounded-full bg-indigo-500"></span>
                                    {{ \App\Support\ModelBrand::active() }}
                                </dd>
                            </div>
                        </dl>

                        <div class="mt-4 flex flex-col gap-2 border-t border-slate-200 pt-3.5 dark:border-white/10">
                            <button
                                type="button"
                                data-rename-chat-trigger
                                data-chat-id="{{ $chat->getKey() }}"
                                data-current-title="{{ $chat->title }}"
                                data-update-url="{{ route('chats.update', $chat) }}"
                                class="btn btn-ghost btn-sm"
                            >
                                <svg class="h-3.5 w-3.5 text-slate-400 dark:text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>
                                </svg>
                                Rename conversation
                            </button>

                            <a
                                href="{{ route('widget.index') }}"
                                class="btn btn-ghost btn-sm"
                            >
                                <svg class="h-3.5 w-3.5 text-slate-400 dark:text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="M2 12h20M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20Z"/>
                                </svg>
                                Embed on a website
                            </a>

                            <a
                                href="{{ route('settings.privacy') }}"
                                class="btn btn-ghost btn-sm"
                            >
                                <svg class="h-3.5 w-3.5 text-slate-400 dark:text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect width="18" height="11" x="3" y="11" rx="2" ry="2"/>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                </svg>
                                Privacy &amp; data
                            </a>

                            <form method="POST" action="{{ route('chats.destroy', $chat) }}" data-delete-chat>
                                @csrf
                                @method('DELETE')
                                <button
                                    type="submit"
                                    class="btn btn-danger btn-sm w-full"
                                >
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M3 6h18m-2 0v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6m3 0V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>
                                    </svg>
                                    Delete conversation
                                </button>
                            </form>
                        </div>
                    </div>

                    {{-- Floating Scroll To Bottom Button --}}
                    <button
                        type="button"
                        data-scroll-to-bottom
                        class="dm-scroll-btn flex h-9 items-center gap-1.5 rounded-full border border-slate-200 bg-white/95 dark:bg-[#0d0f15]/95 px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-lg backdrop-blur-md transition hover:bg-slate-50 dark:border-white/15 dark:text-slate-700"
                        title="Scroll to bottom"
                    >
                        <svg class="h-3.5 w-3.5 text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <path d="m19 12-7 7-7-7M12 19V5" />
                        </svg>
                        <span>New messages</span>
                    </button>

                    {{-- Floating Composer Dock --}}
                    <footer class="pointer-events-none absolute inset-x-0 bottom-0 bg-white px-4 pb-4 pt-6 dark:bg-black sm:px-6">
                        <div class="pointer-events-auto mx-auto w-full max-w-3xl">
                            {{-- Context chips above the input --}}
                            <div class="dm-chip-row mb-2 flex items-center gap-2 overflow-x-auto px-1 pb-0.5">
                                <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-slate-200 bg-white/80 px-2.5 py-1 text-[11px] font-medium text-slate-600 backdrop-blur dark:border-white/10 dark:bg-white/5 dark:text-slate-600">
                                    <svg class="h-3 w-3 text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/>
                                        <polyline points="14 2 14 8 20 8"/>
                                    </svg>
                                    <span class="max-w-[16rem] truncate">{{ $chat->document?->filename ?? 'Support knowledge' }}</span>
                                </span>

                                <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-indigo-200/80 bg-indigo-50/80 px-2.5 py-1 text-[11px] font-medium text-indigo-600 dark:border-indigo-500/20 dark:bg-indigo-500/10 dark:text-indigo-300">
                                    <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2" />
                                    </svg>
                                    Product-support scope
                                </span>

                                <span class="inline-flex shrink-0 items-center rounded-full border border-slate-200 bg-white/80 px-2.5 py-1 text-[11px] font-medium text-slate-500 backdrop-blur dark:border-white/10 dark:bg-white/5 dark:text-slate-500">
                                    {{ $cost }} {{ \Illuminate\Support\Str::plural('credit', $cost) }} per message
                                </span>
                            </div>

                            <form
                                method="POST"
                                action="{{ route('chats.messages', $chat) }}"
                                data-composer
                                data-ready="false"
                                @unless ($historyEnabled) data-enabled="false" @endunless
                                {{-- Border and elevation are owned by `.dm-composer`
                                     so the focused rim can overwrite both without
                                     fighting Tailwind's utilities layer. --}}
                                class="dm-composer relative flex flex-col rounded-3xl border bg-white p-2 dark:bg-[#0d0f15]"
                            >
                                @csrf

                                <span class="dm-sheen" aria-hidden="true"></span>
                                <span class="dm-burst" aria-hidden="true"></span>

                                @unless ($historyEnabled)
                                    <p class="mb-2 flex items-start gap-2 rounded-2xl border border-amber-200 bg-amber-50/80 px-3 py-2 text-xs leading-relaxed text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300">
                                        <svg class="mt-0.5 h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <circle cx="12" cy="12" r="10"/>
                                            <line x1="12" x2="12" y1="8" y2="12"/>
                                            <line x1="12" x2="12.01" y1="16" y2="16"/>
                                        </svg>
                                        <span>
                                            Chat history storage is off, so conversations are not being saved.
                                            <a href="{{ route('settings.privacy') }}" class="font-semibold underline decoration-amber-400 underline-offset-2">Turn it on</a>
                                            to start chatting.
                                        </span>
                                    </p>
                                @endunless

                                <div class="flex items-end gap-1.5">
                                    {{-- Attach / context --}}
                                    <button
                                        type="button"
                                        data-composer-attach
                                        aria-label="Add context"
                                        title="Add context"
                                        class="dm-icon-button mb-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-slate-400 dark:text-slate-500 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-white/10 dark:hover:text-slate-200"
                                    >
                                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M12 5v14M5 12h14"/>
                                        </svg>
                                    </button>

                                    <label class="sr-only" for="chat-message">Message</label>
                                    <textarea
                                        id="chat-message"
                                        name="message"
                                        data-composer-input
                                        rows="1"
                                        maxlength="4000"
                                        @disabled(! $historyEnabled)
                                        placeholder="{{ $historyEnabled ? 'Ask a product support question…' : 'Enable chat history storage to ask a question' }}"
                                        class="max-h-40 min-h-[38px] flex-1 resize-none bg-transparent px-1 py-2 text-[15px] leading-relaxed text-slate-900 placeholder:text-slate-400 focus:outline-none disabled:cursor-not-allowed dark:text-white dark:placeholder:text-slate-500"
                                    ></textarea>

                                    {{-- Stop Generation Button --}}
                                    <button
                                        type="button"
                                        data-composer-stop
                                        class="hidden h-9 w-9 shrink-0 items-center justify-center rounded-full text-rose-500 transition hover:bg-rose-50 dark:hover:bg-rose-500/10"
                                        aria-label="Stop generating"
                                        title="Stop response"
                                    >
                                        <span class="h-3 w-3 rounded-[3px] bg-current"></span>
                                    </button>

                                    {{-- Send Button --}}
                                    <button
                                        type="submit"
                                        data-composer-send
                                        aria-label="Send message"
                                        title="Send message"
                                        @disabled(! $historyEnabled)
                                        class="dm-send mb-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-white"
                                    >
                                        <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M12 19V5" />
                                            <path d="m5 12 7-7 7 7" />
                                        </svg>
                                    </button>
                                </div>
                            </form>

                            <div class="mt-2 flex items-center justify-between gap-3 px-3 text-[11px] text-slate-500">
                                <span class="hidden sm:inline">Business knowledge stays internal; unconfirmed questions are escalated</span>
                                <span class="flex-1 sm:hidden"></span>
                                <span class="tabular-nums">
                                    Press <kbd class="rounded border border-slate-200 bg-slate-100 px-1 py-0.5 text-[10px] font-medium dark:border-white/10 dark:bg-white/10">Enter</kbd> to send
                                </span>
                                <span data-char-count class="hidden tabular-nums text-slate-500"></span>
                            </div>

                            <p data-composer-error class="mt-2 hidden rounded-2xl bg-rose-50 px-4 py-2.5 text-xs text-rose-600 dark:bg-rose-500/10 dark:text-rose-300"></p>
                        </div>
                    </footer>
                @endif
            </section>
        </div>
    </div>
@endsection