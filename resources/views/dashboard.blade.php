@extends('layouts.app')

@section('title', 'Support workspace')

@section('content')
    <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-extrabold tracking-tight text-slate-900 dark:text-white">Support workspace</h1>
            <p class="mt-1.5 text-sm text-slate-500 dark:text-slate-500">
                Welcome back, <span class="font-medium text-slate-700 dark:text-slate-700">{{ $user->name }}</span>. Configure your support assistant, test customer questions, and publish it on your site.
            </p>
            <div class="mt-4 flex flex-wrap gap-2">
                <a href="{{ route('widget.index') }}" class="btn btn-primary inline-flex items-center gap-2">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                    </svg>
                    Build support assistant
                </a>
                <a href="{{ route('chats.index') }}" class="btn btn-secondary inline-flex items-center gap-2">
                    Test support chat
                </a>
            </div>
        </div>

        <div class="inline-flex items-center gap-2 rounded-xl border border-slate-200/80 bg-white px-4 py-2.5 text-sm text-slate-600 shadow-xs dark:border-white/10 dark:bg-white/5 dark:text-slate-600">
            <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
            <span class="font-bold text-slate-900 dark:text-white">{{ $user->credits }}</span>
            {{ \Illuminate\Support\Str::plural('credit', $user->credits) }} remaining
        </div>
    </div>

    <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
        <div>
            <h2 class="text-sm font-bold text-slate-800 dark:text-slate-200">Support knowledge</h2>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Internal product, pricing, setup, and policy references. Customers interact with the assistant, not these files.</p>
        </div>
        <span class="text-xs text-slate-400 dark:text-slate-500">{{ $documents->count() }} sources</span>
    </div>

    <form
        method="POST"
        action="{{ route('documents.store') }}"
        enctype="multipart/form-data"
        data-dropzone
        data-store-url="{{ route('documents.store') }}"
        data-max-kb="{{ config('documents.max_size_kb') }}"
        data-drag="false"
        class="dm-card group mb-8 cursor-pointer rounded-2xl border-2 border-dashed border-slate-300 bg-white transition hover:border-indigo-400 data-[drag=true]:border-indigo-500 data-[drag=true]:bg-indigo-50/60 dark:border-white/15 dark:bg-white/[0.02] dark:hover:border-indigo-500 dark:data-[drag=true]:border-indigo-500 dark:data-[drag=true]:bg-indigo-500/5"
    >
        @csrf

        <input
            type="file"
            name="document"
            accept="application/pdf,.pdf"
            data-file-input
            hidden
        >

        <div class="flex flex-col items-center gap-3 px-6 py-8 text-center">
            <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400 transition group-data-[drag=true]:bg-indigo-100 group-data-[drag=true]:text-indigo-600 dark:bg-white/5 dark:text-slate-500 dark:group-data-[drag=true]:bg-indigo-500/10 dark:group-data-[drag=true]:text-indigo-400">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" />
                </svg>
            </div>

            <div>
                <p class="text-sm font-semibold text-slate-800 dark:text-slate-700">Add a support knowledge PDF</p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-500">
                    PDF only, up to {{ (int) (config('documents.max_size_kb') / 1024) }} MB
                </p>
            </div>

            <div class="flex flex-wrap items-center justify-center gap-2">
                <button
                    type="button"
                    data-browse
                    class="btn btn-secondary"
                >
                    Browse files
                </button>

                <button
                    type="submit"
                    data-upload-trigger
                    class="btn btn-primary"
                >
                    Add to knowledge
                </button>
            </div>
        </div>

        <div data-upload-progress hidden class="px-6 pb-6">
            <div class="h-1.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-white/10">
                <div
                    data-upload-bar
                    class="h-full w-0 rounded-full bg-indigo-500 transition-[width] duration-200"
                ></div>
            </div>
            <p data-upload-label class="mt-2 text-xs text-slate-500 dark:text-slate-500">Uploading…</p>
        </div>

        <p
            data-dropzone-error
            class="mx-6 mb-6 hidden rounded-xl bg-rose-50 px-3 py-2 text-left text-xs text-rose-700 dark:bg-rose-500/10 dark:text-rose-300"
        ></p>
    </form>

    <div class="mb-3 flex items-center justify-between">
        <h2 class="text-sm font-bold text-slate-700 dark:text-slate-600">
            Knowledge sources
            (<span data-document-count class="font-normal text-slate-400 dark:text-slate-500">{{ $documents->count() }}</span>)
        </h2>
    </div>

    <div
        data-empty-state
        @unless ($documents->isEmpty()) hidden @endunless
        class="rounded-2xl border border-slate-200 bg-white p-10 text-center dark:border-white/10 dark:bg-white/[0.02]"
    >
        <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-slate-100 to-slate-50 text-slate-400 dark:from-white/5 dark:to-white/[0.02] dark:text-slate-500">
            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5A3.375 3.375 0 0 0 10.125 2.25H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
            </svg>
        </div>

        <h3 class="mt-5 text-lg font-bold text-slate-900 dark:text-white">No support knowledge yet</h3>
        <p class="mx-auto mt-1.5 max-w-md text-sm text-slate-500 dark:text-slate-500">
            Add product guides, pricing, setup steps, and policies. These sources help the assistant answer customer questions.
        </p>

        <ol class="mx-auto mt-8 grid max-w-3xl gap-4 text-left sm:grid-cols-3">
            <li class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/[0.03]">
                <span class="inline-flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide text-indigo-600 dark:text-indigo-400">
                    <span class="flex h-5 w-5 items-center justify-center rounded-full bg-indigo-100 text-[10px] font-bold dark:bg-indigo-500/20">1</span>
                    Step 1
                </span>
                <p class="mt-2 text-sm font-semibold text-slate-900 dark:text-white">Add product knowledge</p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-500">Upload a PDF reference for internal support answers.</p>
            </li>
            <li class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/[0.03]">
                <span class="inline-flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide text-indigo-600 dark:text-indigo-400">
                    <span class="flex h-5 w-5 items-center justify-center rounded-full bg-indigo-100 text-[10px] font-bold dark:bg-indigo-500/20">2</span>
                    Step 2
                </span>
                <p class="mt-2 text-sm font-semibold text-slate-900 dark:text-white">Let the assistant learn</p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-500">Knowledge is indexed automatically in the background.</p>
            </li>
            <li class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/[0.03]">
                <span class="inline-flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide text-indigo-600 dark:text-indigo-400">
                    <span class="flex h-5 w-5 items-center justify-center rounded-full bg-indigo-100 text-[10px] font-bold dark:bg-indigo-500/20">3</span>
                    Step 3
                </span>
                <p class="mt-2 text-sm font-semibold text-slate-900 dark:text-white">Test customer questions</p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-500">Check support answers, then publish your bot.</p>
            </li>
        </ol>
    </div>

    <div data-document-list class="grid gap-4">
        @foreach ($documents as $document)
            @include('documents.card', ['document' => $document])
        @endforeach
    </div>

    <template data-status-url="{{ route('documents.status', ['document' => '__id__']) }}"></template>
@endsection
