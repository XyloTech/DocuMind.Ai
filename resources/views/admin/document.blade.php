@extends('layouts.app')

@section('title', $document->filename.' · '.config('app.name'))

@section('content')
    <div class="mx-auto w-full max-w-4xl">
        <a href="{{ route('admin.dashboard', ['tab' => 'knowledge']) }}" class="text-xs font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
            ← Knowledge
        </a>

        <div class="mt-3 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-extrabold tracking-tight text-slate-900 dark:text-white break-all">
                    {{ $document->filename }}
                </h1>
                <p class="mt-1.5 text-sm text-slate-500 dark:text-slate-400">
                    {{ auth()->user()->isAdmin() ? ($owner?->name ?? 'Deleted account') : 'Account #'.$document->user_id }}
                    · uploaded {{ $document->created_at?->diffForHumans() }}
                </p>
            </div>

            <span @class([
                'rounded-full px-3 py-1 text-xs font-semibold',
                $document->isProcessed() ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
            ])>{{ $document->status->label() }}</span>
        </div>

        <section class="mt-5 rounded-xl border border-slate-200 bg-white p-5 dark:border-white/10 dark:bg-[#0d0f15]">
            <h2 class="text-sm font-bold text-slate-900 dark:text-white">Review</h2>
            <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-3 text-xs sm:grid-cols-3">
                <div>
                    <dt class="text-slate-500 dark:text-slate-400">Pages</dt>
                    <dd class="mt-0.5 font-semibold text-slate-800 dark:text-slate-200 tabular-nums">{{ number_format($document->page_count) }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500 dark:text-slate-400">Sections</dt>
                    <dd class="mt-0.5 font-semibold text-slate-800 dark:text-slate-200 tabular-nums">{{ number_format($document->chunk_count) }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500 dark:text-slate-400">Size</dt>
                    <dd class="mt-0.5 font-semibold text-slate-800 dark:text-slate-200">{{ \Illuminate\Support\Number::fileSize($document->size_bytes) }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500 dark:text-slate-400">Indexed</dt>
                    <dd class="mt-0.5 font-semibold text-slate-800 dark:text-slate-200">{{ $document->processed_at?->diffForHumans() ?? 'Not yet' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500 dark:text-slate-400">Document ID</dt>
                    <dd class="mt-0.5 font-semibold text-slate-800 dark:text-slate-200">#{{ $document->getKey() }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500 dark:text-slate-400">Account</dt>
                    <dd class="mt-0.5 font-semibold text-slate-800 dark:text-slate-200">#{{ $document->user_id }}</dd>
                </div>
            </dl>

            @if ($document->error_message)
                <p class="mt-4 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                    {{ $document->error_message }}
                </p>
            @endif

            @can('update', $document)
                <form method="POST" action="{{ route('admin.documents.update', $document) }}" class="mt-5 flex flex-wrap items-end gap-2 border-t border-slate-100 pt-4 dark:border-white/5">
                    @csrf
                    @method('PATCH')
                    <label class="min-w-0 flex-1">
                        <span class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300">Document name</span>
                        <input
                            name="filename"
                            value="{{ $document->filename }}"
                            required
                            maxlength="255"
                            class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none dark:border-white/15 dark:bg-white/5 dark:text-white"
                        >
                    </label>
                    <label class="min-w-0 flex-1">
                        <span class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300">Reason (logged)</span>
                        <input
                            name="reason"
                            required
                            minlength="8"
                            maxlength="255"
                            placeholder="Why this name changed"
                            class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none dark:border-white/15 dark:bg-white/5 dark:text-white dark:placeholder:text-slate-500"
                        >
                    </label>
                    <button type="submit" class="btn btn-primary btn-sm">Save name</button>
                </form>
            @endcan

            @can('delete', $document)
                <form method="POST" action="{{ route('admin.documents.destroy', $document) }}" class="mt-3 border-t border-slate-100 pt-4 dark:border-white/5" data-admin-confirm="Delete “{{ $document->filename }}” for good? The file, its sections and every chat about it will be removed.">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-secondary btn-sm text-rose-600 dark:text-rose-400">Delete document</button>
                </form>
            @endcan
        </section>

        @if ($document->suggestedQuestions() !== [])
            <section class="mt-4 rounded-xl border border-slate-200 bg-white p-5 dark:border-white/10 dark:bg-[#0d0f15]">
                <h2 class="text-sm font-bold text-slate-900 dark:text-white">Suggested questions</h2>
                <ul class="mt-3 space-y-1.5 text-xs text-slate-600 dark:text-slate-300">
                    @foreach ($document->suggestedQuestions() as $question)
                        <li class="rounded-lg bg-slate-50 px-3 py-2 dark:bg-white/5">{{ $question }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="mt-4 rounded-xl border border-slate-200 bg-white p-5 dark:border-white/10 dark:bg-[#0d0f15]">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-bold text-slate-900 dark:text-white">Indexed sections</h2>
                <p class="text-[11px] text-slate-500 dark:text-slate-400">First {{ $chunks->count() }} of {{ number_format($document->chunk_count) }}</p>
            </div>

            <div class="mt-3 space-y-2">
                @forelse ($chunks as $chunk)
                    <article class="rounded-lg border border-slate-100 bg-slate-50 p-3 dark:border-white/5 dark:bg-white/[0.03]">
                        <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                            Section {{ $chunk->chunk_index + 1 }}
                            @if ($chunk->page_from !== null)
                                · pages {{ $chunk->page_from }}@if ($chunk->page_to !== null && $chunk->page_to !== $chunk->page_from)–{{ $chunk->page_to }}@endif
                            @endif
                        </p>
                        <p class="mt-1 line-clamp-4 whitespace-pre-wrap text-xs leading-relaxed text-slate-700 dark:text-slate-300">{{ $chunk->chunk_text }}</p>
                    </article>
                @empty
                    <p class="py-4 text-center text-xs text-slate-500 dark:text-slate-400">No indexed sections yet.</p>
                @endforelse
            </div>
        </section>
    </div>
@endsection
