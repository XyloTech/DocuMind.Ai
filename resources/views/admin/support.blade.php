@extends('layouts.app')

@section('title', 'Support conversation #'.$conversation->getKey().' · '.config('app.name'))

@section('content')
    <div class="mx-auto w-full max-w-3xl">
        <a href="{{ route('admin.dashboard', ['tab' => 'support']) }}" class="text-xs font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
            ← Support inbox
        </a>

        <div class="mt-3 flex flex-wrap items-end justify-between gap-4 border-b border-slate-200 pb-5 dark:border-white/10">
            <div>
            <h1 class="text-2xl font-semibold text-slate-900 dark:text-white">
                    Conversation #{{ $conversation->getKey() }}
                </h1>
                <p class="mt-1.5 text-sm text-slate-500 dark:text-slate-400">
                    @if ($conversation->widgetConversation !== null)
                        {{ $conversation->originLabel() }}
                    @elseif (auth()->user()->isAdmin())
                        {{ $conversation->originLabel() }}
                    @else
                        Account #{{ $conversation->user_id }}
                    @endif
                    · opened {{ $conversation->created_at?->diffForHumans() }}
                    @if ($conversation->chat_id !== null)
                        · from chat #{{ $conversation->chat_id }}
                    @endif
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2 text-xs">
                <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 font-semibold text-slate-700 dark:border-white/10 dark:bg-white/5 dark:text-slate-300">
                    {{ $conversation->status->label() }}
                </span>
                <span class="text-slate-500 dark:text-slate-400">
                    {{ $conversation->agent?->name ?? 'Unassigned' }}
                </span>
            </div>
        </div>

        <div class="mt-4 space-y-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-[#0d0f15]">
            @forelse ($messages as $message)
                <article
                    @class([
                        'flex',
                        'justify-end' => $message->role === \App\Enums\SupportMessageRole::User,
                        'justify-start' => $message->role !== \App\Enums\SupportMessageRole::User,
                    ])
                >
                    @if ($message->role === \App\Enums\SupportMessageRole::System)
                        <p class="rounded-lg bg-slate-50 px-3 py-1.5 text-center text-[11px] font-medium text-slate-500 dark:bg-white/5 dark:text-slate-400">
                            {{ $message->content }}
                        </p>
                    @else
                        <div
                            @class([
                                'max-w-[85%] rounded-2xl px-4 py-2.5 text-sm leading-relaxed',
                                'border border-indigo-200 bg-indigo-50 text-indigo-900 dark:border-indigo-500/30 dark:bg-indigo-500/10 dark:text-indigo-100' => $message->role === \App\Enums\SupportMessageRole::User,
                                'border border-slate-200 bg-slate-50 text-slate-800 dark:border-white/10 dark:bg-white/5 dark:text-slate-200' => $message->role === \App\Enums\SupportMessageRole::Agent,
                            ])
                        >
                            <p class="mb-1 text-[10px] font-semibold uppercase tracking-wide opacity-70">
                                {{ $message->role === \App\Enums\SupportMessageRole::User
                                    ? (auth()->user()->isAdmin() ? ($message->sender?->name ?? 'Visitor') : 'Visitor')
                                    : ($message->sender?->name ?? 'Support') }}
                                <span aria-hidden="true">·</span>
                                {{ $message->created_at?->diffForHumans() }}
                            </p>
                            <p class="whitespace-pre-wrap break-words">{{ $message->content }}</p>
                        </div>
                    @endif
                </article>
            @empty
                <p class="py-6 text-center text-sm text-slate-500 dark:text-slate-400">No messages yet.</p>
            @endforelse
        </div>

        @if ($conversation->status->isLive())
            <form method="POST" action="{{ route('admin.support.reply', $conversation) }}" class="mt-4 flex items-end gap-2">
                @csrf
                <label class="sr-only" for="admin-reply">Reply to the visitor</label>
                <textarea
                    id="admin-reply"
                    name="message"
                    rows="2"
                    required
                    maxlength="4000"
                    placeholder="Reply to the visitor…"
                    class="min-w-0 flex-1 resize-none rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-600/20 dark:border-white/15 dark:bg-white/5 dark:text-white dark:placeholder:text-slate-500"
                ></textarea>
                <button type="submit" class="btn btn-primary">Reply</button>
            </form>

            <form method="POST" action="{{ route('admin.support.resolve', $conversation) }}" class="mt-3" data-admin-confirm="Mark this conversation as resolved? The visitor's chat will show it as closed.">
                @csrf
                <button type="submit" class="btn btn-secondary btn-sm">Resolve conversation</button>
            </form>
        @else
            <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">
                This conversation is closed. The transcript above is kept for reference.
            </p>
        @endif

        @if ($errors->any())
            <p class="mt-3 text-xs font-medium text-rose-600 dark:text-rose-400">{{ $errors->first() }}</p>
        @endif
    </div>
@endsection
