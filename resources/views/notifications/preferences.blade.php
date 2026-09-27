@extends('layouts.app')

@section('title', 'Notification preferences · '.config('app.name'))

@section('content')
    <div class="mx-auto w-full max-w-3xl">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                    Notification preferences
                </h1>
                <p class="mt-1.5 max-w-xl text-sm leading-relaxed text-slate-500 dark:text-slate-500">
                    Choose what reaches the bell, your browser and your email. Security and billing
                    alerts always appear in-app so nothing critical can be muted by accident.
                </p>
            </div>

            <a href="{{ route('notifications.index') }}" class="btn btn-secondary btn-sm">
                Open notification centre
            </a>
        </div>

        <form method="POST" action="{{ route('settings.notifications.update') }}">
            @csrf
            @method('PATCH')

            {{-- Delivery channels --}}
            <section class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs dark:border-white/10 dark:bg-white/[0.02]">
                <div class="border-b border-slate-100 bg-slate-50/70 px-5 py-4 dark:border-white/10 dark:bg-white/[0.02]">
                    <h2 class="text-sm font-bold text-slate-900 dark:text-white">Delivery</h2>
                </div>

                <div class="space-y-4 p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <label for="pref-non-essential" class="cursor-pointer text-sm font-semibold text-slate-900 dark:text-white">
                                Show non-essential notifications
                            </label>
                            <p class="mt-0.5 text-xs leading-relaxed text-slate-500 dark:text-slate-500">
                                Day-to-day activity — new conversations, uploads finishing, widget
                                settings changed. Turn this off to keep only security, team, credit
                                and failure alerts.
                            </p>
                        </div>
                        <span class="inline-flex shrink-0 items-center">
                            <input type="hidden" name="non_essential" value="0">
                            <input
                                type="checkbox"
                                name="non_essential"
                                value="1"
                                id="pref-non-essential"
                                class="dm-toggle mt-1"
                                @checked(old('non_essential', $preferences['non_essential']))
                            >
                        </span>
                    </div>

                    <div class="h-px bg-slate-100 dark:bg-white/10"></div>

                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <label for="pref-email" class="cursor-pointer text-sm font-semibold text-slate-900 dark:text-white">
                                Email me important updates
                            </label>
                            <p class="mt-0.5 text-xs leading-relaxed text-slate-500 dark:text-slate-500">
                                An extra copy by email for failures, credit changes, invitations and
                                security events. Pick the categories below. Emails never include
                                visitor transcripts or personal data.
                            </p>
                        </div>
                        <span class="inline-flex shrink-0 items-center">
                            <input type="hidden" name="email" value="0">
                            <input
                                type="checkbox"
                                name="email"
                                value="1"
                                id="pref-email"
                                class="dm-toggle mt-1"
                                @checked(old('email', $preferences['email']))
                            >
                        </span>
                    </div>

                    <div class="h-px bg-slate-100 dark:bg-white/10"></div>

                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <span class="text-sm font-semibold text-slate-900 dark:text-white">Browser notifications</span>
                            <p class="mt-0.5 text-xs leading-relaxed text-slate-500 dark:text-slate-500">
                                Get a native desktop notification while this tab is in the background.
                                Your browser will ask for permission first.
                            </p>
                            <p class="mt-1.5 flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    data-browser-permission
                                    class="btn btn-secondary btn-xs"
                                >
                                    Enable browser notifications
                                </button>
                                <span data-browser-permission-status class="text-[11px] text-slate-500 dark:text-slate-500">
                                    Checking permission…
                                </span>
                            </p>
                        </div>
                        <span class="inline-flex shrink-0 items-center">
                            <input type="hidden" name="browser" value="0">
                            <input
                                type="checkbox"
                                name="browser"
                                value="1"
                                id="pref-browser"
                                class="dm-toggle mt-1"
                                @checked(old('browser', $preferences['browser']))
                            >
                        </span>
                    </div>
                </div>
            </section>

            {{-- Per-category controls --}}
            <section class="mt-4 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs dark:border-white/10 dark:bg-white/[0.02]">
                <div class="border-b border-slate-100 bg-slate-50/70 px-5 py-4 dark:border-white/10 dark:bg-white/[0.02]">
                    <h2 class="text-sm font-bold text-slate-900 dark:text-white">By category</h2>
                    <p class="mt-0.5 text-[11px] text-slate-500 dark:text-slate-500">
                        In-app toggles apply to non-essential items; security, invitations and credit
                        changes stay in-app regardless. Email requires the master switch above.
                    </p>
                </div>

                <div class="divide-y divide-slate-100 dark:divide-white/10">
                    @foreach ($categories as $category)
                        <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="dm-notif-icon dm-notif-icon--{{ $category->value }} h-8 w-8" aria-hidden="true">
                                    @include('partials.notification-icon', ['category' => $category])
                                </span>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-slate-900 dark:text-white">
                                        {{ $category->label() }}
                                    </p>
                                    @if (! isset($typesByEmail[$category->value]))
                                        <p class="text-[11px] text-slate-400 dark:text-slate-500">In-app only</p>
                                    @endif
                                </div>
                            </div>

                            <div class="flex items-center gap-5">
                                <label class="flex cursor-pointer items-center gap-2 text-xs font-medium text-slate-600 dark:text-slate-400">
                                    In-app
                                    <input type="hidden" name="categories[{{ $category->value }}][in_app]" value="0">
                                    <input
                                        type="checkbox"
                                        name="categories[{{ $category->value }}][in_app]"
                                        value="1"
                                        class="dm-toggle"
                                        @checked(old('categories.'.$category->value.'.in_app', $preferences['categories'][$category->value]['in_app']))
                                    >
                                </label>

                                <label class="flex cursor-pointer items-center gap-2 text-xs font-medium text-slate-600 dark:text-slate-400">
                                    Email
                                    <input type="hidden" name="categories[{{ $category->value }}][email]" value="0">
                                    <input
                                        type="checkbox"
                                        name="categories[{{ $category->value }}][email]"
                                        value="1"
                                        class="dm-toggle"
                                        @checked(old('categories.'.$category->value.'.email', $preferences['categories'][$category->value]['email']))
                                    >
                                </label>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            <div class="mt-5 flex items-center justify-end gap-3">
                <a href="{{ route('notifications.index') }}" class="btn btn-ghost btn-sm">Cancel</a>
                <button type="submit" class="btn btn-primary btn-sm">Save preferences</button>
            </div>
        </form>
    </div>
@endsection
