@extends('layouts.app')

@section('title', 'Notifications · '.config('app.name'))

@section('content')
    <div class="mx-auto w-full max-w-3xl">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                    Notifications
                </h1>
                <p class="mt-1.5 max-w-xl text-sm leading-relaxed text-slate-500 dark:text-slate-500">
                    Everything the platform raised for your account: new conversations, uploads and
                    indexing results, team changes, credit and security events.
                    @if ($unreadCount > 0)
                        <span class="font-semibold text-indigo-600 dark:text-indigo-400">{{ $unreadCount }} unread.</span>
                    @endif
                </p>
            </div>

            <div class="flex items-center gap-2">
                <a href="{{ route('settings.notifications') }}" class="btn btn-secondary btn-sm">
                    Preferences
                </a>
                <button
                    type="button"
                    data-mark-all
                    data-notifications-base="{{ route('notifications.index') }}"
                    class="btn btn-primary btn-sm"
                    @disabled($unreadCount === 0)
                >
                    Mark all read
                </button>
            </div>
        </div>

        {{-- Filters are an ordinary GET form: the whole page works without JS. --}}
        <form method="GET" action="{{ route('notifications.index') }}" class="mt-6 flex flex-wrap items-center gap-2">
            <label class="sr-only" for="notif-q">Search notifications</label>
            <input
                type="search"
                name="q"
                id="notif-q"
                value="{{ $filters['q'] }}"
                placeholder="Search notifications…"
                max="80"
                class="min-w-0 flex-1 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-900 placeholder:text-slate-500 focus:border-indigo-600 focus:outline-none focus:ring-2 focus:ring-indigo-600/30 dark:border-white/20 dark:bg-white/5 dark:text-white dark:placeholder:text-slate-400 sm:w-64"
            >

            <label class="sr-only" for="notif-status">Read state</label>
            <select name="status" id="notif-status" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-900 dark:border-white/20 dark:bg-[#0d0f15] dark:text-white">
                <option value="all" @selected($filters['status'] === 'all')>All states</option>
                <option value="unread" @selected($filters['status'] === 'unread')>Unread</option>
                <option value="read" @selected($filters['status'] === 'read')>Read</option>
            </select>

            <label class="sr-only" for="notif-category">Category</label>
            <select name="category" id="notif-category" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-900 dark:border-white/20 dark:bg-[#0d0f15] dark:text-white">
                <option value="">All categories</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->value }}" @selected($filters['category'] === $category->value)>
                        {{ $category->label() }}
                    </option>
                @endforeach
            </select>

            <button type="submit" class="btn btn-secondary btn-sm">Filter</button>

            @if ($filters['category'] !== '' || $filters['status'] !== 'all' || $filters['q'] !== '')
                <a href="{{ route('notifications.index') }}" class="btn btn-ghost btn-sm">Clear</a>
            @endif
        </form>

        <div class="mt-4 space-y-2">
            @forelse ($notifications as $notification)
                <article
                    class="dm-notif-row @unless($notification->read_at) dm-notif-row--unread @endunless"
                    data-notification-id="{{ $notification->getKey() }}"
                    data-notification-link="{{ $notification->link }}"
                >
                    <span class="dm-notif-icon dm-notif-icon--{{ $notification->category->value }}" aria-hidden="true">
                        @include('partials.notification-icon', ['category' => $notification->category])
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                            @if ($notification->read_at === null)
                                <span class="dm-notif-dot" aria-label="Unread"></span>
                            @endif
                            <h2 class="truncate text-sm font-semibold text-slate-900 dark:text-white">
                                {{ $notification->title }}
                            </h2>
                            <span class="dm-notif-cat">{{ $notification->category->label() }}</span>
                        </div>

                        @if ($notification->body)
                            <p class="mt-0.5 text-xs leading-relaxed text-slate-500 dark:text-slate-500">
                                {{ $notification->body }}
                            </p>
                        @endif

                        <p class="mt-1 flex flex-wrap items-center gap-2 text-[11px] text-slate-400 dark:text-slate-500">
                            <time datetime="{{ $notification->created_at->toIso8601String() }}" title="{{ $notification->created_at->format('j M Y, H:i') }}">
                                {{ $notification->created_at->diffForHumans() }}
                            </time>
                            @if ($notification->link)
                                <span aria-hidden="true">·</span>
                                <span class="font-semibold text-indigo-500 dark:text-indigo-400">{{ $notification->link_label ?? 'Open' }}</span>
                            @endif
                        </p>
                    </div>
                </article>
            @empty
                <div class="dm-notif-empty-card">
                    <svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a24 24 0 0 0 3.844-.537M6.75 3.5A3.75 3.75 0 0 0 3 7.25v3.393c0 .463.168.913.47 1.27l1.09 1.286a.75.75 0 0 1 .13.406v.75A4.75 4.75 0 0 0 10.75 21h2.5a4.75 4.75 0 0 0 4.75-4.75v-.75a.75.75 0 0 1 .13-.406l1.09-1.286c.302-.357.47-.807.47-1.27V7.25A3.75 3.75 0 0 0 15.75 3.5h-9Z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 7.25a2.25 2.25 0 1 1 4.5 0"/>
                    </svg>
                    <p class="mt-3 text-sm font-semibold text-slate-700 dark:text-slate-200">
                        @if ($filters['category'] !== '' || $filters['status'] !== 'all' || $filters['q'] !== '')
                            No notifications match these filters.
                        @else
                            Nothing here yet.
                        @endif
                    </p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-500">
                        @if ($filters['category'] !== '' || $filters['status'] !== 'all' || $filters['q'] !== '')
                            Try clearing the search or choosing another category.
                        @else
                            New conversations, uploads and account events will show up here as they happen.
                        @endif
                    </p>
                    @if ($filters['category'] !== '' || $filters['status'] !== 'all' || $filters['q'] !== '')
                        <a href="{{ route('notifications.index') }}" class="btn btn-secondary btn-sm mt-4">Clear filters</a>
                    @endif
                </div>
            @endforelse
        </div>

        <div class="mt-5">
            {{ $notifications->links() }}
        </div>
    </div>
@endsection
