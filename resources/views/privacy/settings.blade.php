@extends('layouts.app')

@section('title', 'Privacy & data · '.config('app.name'))

@section('content')
    <div class="mx-auto w-full max-w-3xl">
        <div class="mb-7 flex flex-wrap items-end justify-between gap-4 border-b border-slate-200 pb-5 dark:border-white/10">
            <div>
                <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Workspace / Settings</p>
                <h1 class="mt-1 text-2xl font-semibold text-slate-900 dark:text-white">Privacy &amp; data</h1>
                <p class="mt-1.5 max-w-xl text-sm leading-relaxed text-slate-500 dark:text-slate-400">
                    Decide what is stored, what may be used for training, and how long anything is kept.
                    Every change here is recorded and can be reversed.
                </p>
            </div>
            <a
                href="{{ route('privacy.policy') }}"
                class="btn btn-secondary btn-sm inline-flex items-center gap-1.5"
            >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                </svg>
                Privacy policy
            </a>
        </div>

        {{-- Consent status + toggles --}}
        <section class="mt-5 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-[#0d0f15]">
            <div class="border-b border-slate-100 bg-slate-50/70 px-5 py-4 dark:border-white/10 dark:bg-white/[0.02]">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sm font-bold text-slate-900 dark:text-white">Your choices</h2>

                    @if ($user->hasGivenConsent())
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">
                            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <path d="M20 6 9 17l-5-5"/>
                            </svg>
                            Consent on file
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300">
                            Awaiting your answer
                        </span>
                    @endif
                </div>

                @if ($user->hasGivenConsent())
                    <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-500">
                        Recorded {{ $user->privacy_consent_at->diffForHumans() }}
                        @if ($user->privacy_consent_version)
                            &middot; notice version {{ $user->privacy_consent_version }}
                        @endif
                    </p>
                @endif
            </div>

            <form method="POST" action="{{ route('settings.privacy.update') }}">
                @csrf
                @method('PATCH')

                <div class="space-y-4 p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <label for="store-chat-history" class="cursor-pointer text-sm font-semibold text-slate-900 dark:text-white">
                                Save my chat history
                            </label>
                            <p class="mt-0.5 text-xs leading-relaxed text-slate-500 dark:text-slate-500">
                                Required to chat. Questions and answers are kept in your account so you can
                                reopen a conversation. Turning this off stops any further storage; anything
                                already stored stays until you delete it below.
                            </p>
                        </div>
                        <input
                            type="checkbox"
                            name="store_chat_history"
                            value="1"
                            id="store-chat-history"
                            class="dm-toggle mt-1"
                            @checked(old('store_chat_history', $user->store_chat_history))
                        >
                    </div>

                    <div class="h-px bg-slate-100 dark:bg-white/10"></div>

                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <label for="allow-model-training" class="cursor-pointer text-sm font-semibold text-slate-900 dark:text-white">
                                Use my conversations to improve the model
                            </label>
                            <p class="mt-0.5 text-xs leading-relaxed text-slate-500 dark:text-slate-500">
                                Anonymised text only: emails, names, credentials and other personal details are
                                removed before anything is used, and your account details are never included.
                                Revoking this stops future use immediately.
                            </p>
                        </div>
                        <input
                            type="checkbox"
                            name="allow_model_training"
                            value="1"
                            id="allow-model-training"
                            class="dm-toggle mt-1"
                            @checked(old('allow_model_training', $user->allow_model_training))
                        >
                    </div>

                    <div class="h-px bg-slate-100 dark:bg-white/10"></div>

                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <label for="chat-retention" class="text-sm font-semibold text-slate-900 dark:text-white">
                                Keep my conversations for
                            </label>
                            <p class="mt-0.5 text-xs leading-relaxed text-slate-500 dark:text-slate-500">
                                Older conversations are deleted automatically. Leave on "until I delete it" to
                                keep them for as long as your account exists.
                            </p>
                        </div>

                        <select
                            name="chat_retention_days"
                            id="chat-retention"
                            class="rounded-xl border border-slate-200 bg-white dark:bg-[#0d0f15] px-3 py-2 text-sm text-slate-900 shadow-xs focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-white/10 dark:text-white"
                        >
                            <option value="">Until I delete it</option>
                            @foreach ($retentionOptions as $days)
                                <option value="{{ $days }}" @selected((int) old('chat_retention_days', $user->chat_retention_days ?? 0) === (int) $days)>
                                    {{ $days }} days
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 bg-slate-50/60 px-5 py-3.5 dark:border-white/10 dark:bg-white/[0.02]">
                    <button
                        type="submit"
                        class="btn btn-primary inline-flex items-center gap-2"
                    >
                        Save preferences
                    </button>

                    <a
                        href="{{ route('chats.index') }}"
                        class="text-xs font-medium text-slate-500 underline decoration-slate-300 underline-offset-2 transition hover:text-slate-800 dark:text-slate-500 dark:decoration-slate-600 dark:hover:text-slate-200"
                    >
                        Back to chat
                    </a>
                </div>
            </form>

            @if ($user->hasGivenConsent())
                <form
                    method="POST"
                    action="{{ route('settings.privacy.revoke') }}"
                    class="border-t border-slate-100 px-5 py-3.5 dark:border-white/10"
                >
                    @csrf
                    <button
                        type="submit"
                        class="btn btn-danger btn-sm inline-flex items-center gap-2"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M3 6h18m-2 0v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6m3 0V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>
                        </svg>
                        Withdraw consent and switch everything off
                    </button>
                </form>
            @endif
        </section>

        {{-- Export / delete --}}
        <section class="mt-5 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-[#0d0f15]">
            <div class="border-b border-slate-100 px-5 py-4 dark:border-white/10">
                <h2 class="text-sm font-bold text-slate-900 dark:text-white">Your data</h2>
                <p class="mt-1 text-xs leading-relaxed text-slate-500 dark:text-slate-500">
                    Currently stored: {{ $conversationCount }} {{ \Illuminate\Support\Str::plural('conversation', $conversationCount) }}
                    and {{ $documentCount }} {{ \Illuminate\Support\Str::plural('document', $documentCount) }}.
                </p>
            </div>

            <div class="space-y-5 p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-slate-900 dark:text-white">Export a copy</p>
                        <p class="mt-0.5 text-xs leading-relaxed text-slate-500 dark:text-slate-500">
                            A JSON file with your account details, conversations, documents, widget sites and
                            privacy activity. Passwords and security keys are never included.
                        </p>
                    </div>

                    <a
                        href="{{ route('settings.privacy.export') }}"
                        class="btn btn-secondary btn-sm inline-flex shrink-0 items-center gap-2"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="7 10 12 15 17 10"/>
                            <line x1="12" x2="12" y1="15" y2="3"/>
                        </svg>
                        Download my data
                    </a>
                </div>

                <div class="h-px bg-slate-100 dark:bg-white/10"></div>

                <form method="POST" action="{{ route('settings.privacy.destroy') }}" class="space-y-3">
                    @csrf
                    @method('DELETE')

                    <div>
                        <p class="text-sm font-semibold text-rose-600 dark:text-rose-400">Delete my data</p>
                        <p class="mt-0.5 text-xs leading-relaxed text-slate-500 dark:text-slate-500">
                            Permanently removes your conversations, messages, uploaded documents (including the
                            files) and widget sites. Your account stays open so you can start again. This
                            cannot be undone.
                        </p>
                    </div>

                    <div>
                        <label for="delete-confirmation" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                            Type DELETE to confirm
                        </label>
                        <input
                            type="text"
                            name="confirmation"
                            id="delete-confirmation"
                            required
                            autocomplete="off"
                            placeholder="DELETE"
                            class="w-full rounded-xl border border-slate-200 bg-white dark:bg-[#0d0f15] px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-rose-400 focus:outline-none focus:ring-2 focus:ring-rose-500/20 dark:border-white/10 dark:text-white"
                        >
                    </div>

                    <button
                        type="submit"
                        class="btn btn-danger inline-flex items-center gap-2"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M3 6h18m-2 0v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6m3 0V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>
                        </svg>
                        Permanently delete my data
                    </button>
                </form>
            </div>
        </section>

        {{-- How data is handled --}}
        <section class="mt-5 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-[#0d0f15]">
            <div class="border-b border-slate-100 px-5 py-4 dark:border-white/10">
                <h2 class="text-sm font-bold text-slate-900 dark:text-white">How your data is handled</h2>
            </div>

            <ul class="divide-y divide-slate-100 dark:divide-white/10">
                @foreach ([
                    ['Minimisation', 'Only what the feature needs is stored: your documents, your answers, and the choices on this page.'],
                    ['Encryption', 'Sensitive records — including this activity trail — are encrypted at rest, and traffic runs over HTTPS.'],
                    ['Access control', 'Everything is scoped to your signed-in account, with separate permissions for administrative screens.'],
                    ['Anonymisation', 'Before any text is used for training, emails, names, credentials and other identifiers are replaced with placeholders.'],
                    ['Audit trail', 'Every consent change, export and deletion is logged below so you can see what happened and when.'],
                    ['Retention', 'Set a window above and old conversations are purged automatically; deletion is permanent.'],
                ] as [$heading, $body])
                    <li class="flex gap-3 px-5 py-3.5">
                        <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-300">
                            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                <path d="M20 6 9 17l-5-5"/>
                            </svg>
                        </span>
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold text-slate-900 dark:text-white">{{ $heading }}</span>
                            <span class="mt-0.5 block text-xs leading-relaxed text-slate-500 dark:text-slate-500">{{ $body }}</span>
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>

        {{-- Activity trail --}}
        <section class="mt-5 mb-4 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs dark:border-white/10 dark:bg-white/[0.02]">
            <div class="border-b border-slate-100 px-5 py-4 dark:border-white/10">
                <h2 class="text-sm font-bold text-slate-900 dark:text-white">Privacy activity</h2>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-500">
                    Consent changes, exports and deletions for your account.
                </p>
            </div>

            @if ($activity->isEmpty())
                <p class="px-5 py-5 text-sm text-slate-500 dark:text-slate-500">No privacy activity recorded yet.</p>
            @else
                <ul class="divide-y divide-slate-100 dark:divide-white/10">
                    @foreach ($activity as $entry)
                        <li class="flex items-start justify-between gap-4 px-5 py-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm text-slate-700 dark:text-slate-600">{{ $entry->summary }}</p>
                                <p class="mt-0.5 text-[11px] font-medium uppercase tracking-wide text-slate-400 dark:text-slate-500">
                                    {{ $entry->action }}
                                </p>
                            </div>
                            <span class="shrink-0 text-[11px] tabular-nums text-slate-400 dark:text-slate-500">
                                {{ $entry->created_at?->diffForHumans() }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
@endsection
