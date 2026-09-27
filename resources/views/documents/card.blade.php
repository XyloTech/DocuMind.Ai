@php($badge = match ($document->status->value) {
    'processing' => 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300',
    'processed' => 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300',
    'failed' => 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300',
    default => 'border-slate-200 bg-slate-50 text-slate-600',
})

<article
    data-document-card
    data-document-id="{{ $document->getKey() }}"
    data-status="{{ $document->status->value }}"
    class="rounded-2xl border border-slate-200 bg-white dark:bg-[#0d0f15] p-4 sm:p-5"
>
    <div class="flex items-start gap-4">
        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5A3.375 3.375 0 0 0 10.125 2.25H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
            </svg>
        </div>

        <div class="min-w-0 flex-1">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium text-slate-900">{{ $document->filename }}</p>
                    <p class="mt-0.5 text-xs text-slate-500">
                        {{ $document->humanSize() }}

                        @if ($document->page_count !== null)
                            &middot; {{ $document->page_count }} {{ \Illuminate\Support\Str::plural('page', $document->page_count) }}
                        @endif

                        @if ($document->chunk_count > 0)
                            &middot; {{ $document->chunk_count }} {{ \Illuminate\Support\Str::plural('chunk', $document->chunk_count) }}
                        @endif

                        &middot; {{ $document->created_at->diffForHumans() }}
                    </p>
                </div>

                <span class="shrink-0 rounded-full border px-2.5 py-0.5 text-xs font-medium {{ $badge }}">
                    {{ $document->status->label() }}
                </span>
            </div>

            @unless ($document->status->isTerminal())
                <div class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                    <div
                        class="h-full rounded-full bg-indigo-500 transition-[width] duration-300"
                        style="width: {{ $document->progress }}%"
                        role="progressbar"
                        aria-valuenow="{{ $document->progress }}"
                        aria-valuemin="0"
                        aria-valuemax="100"
                    ></div>
                </div>
            @endunless

            @if ($document->error_message !== null && $document->status->value === 'failed')
                <p class="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                    {{ $document->error_message }}
                </p>
            @endif

            <div class="mt-3 flex flex-wrap items-center justify-end gap-2">
                @if ($document->status->value === 'failed')
                    <form method="POST" action="{{ route('documents.retry', $document) }}">
                        @csrf

                        <button
                            type="submit"
                            class="btn btn-primary btn-sm"
                        >
                            Try again
                        </button>
                    </form>
                @endif

                @if ($document->isProcessed())
                    <form method="POST" action="{{ route('chats.store') }}">
                        @csrf
                        <input type="hidden" name="document_id" value="{{ $document->getKey() }}">

                        <button
                            type="submit"
                            class="btn btn-primary btn-sm"
                        >
                            Test support
                        </button>
                    </form>
                @endif

                <form method="POST" action="{{ route('documents.destroy', $document) }}" data-delete-form>
                    @csrf
                    @method('DELETE')

                    <button
                        type="submit"
                        class="btn btn-danger btn-sm"
                    >
                        Delete
                    </button>
                </form>
            </div>
        </div>
    </div>
</article>