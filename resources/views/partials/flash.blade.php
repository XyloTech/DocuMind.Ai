{{-- Flash messenger: the status line is handed to the JS toast system on
     boot (styled, dismissible, aria-live) instead of a full-width banner;
     validation errors keep their own readable list below. --}}
@if (session('status'))
    <div
        data-page-toasts="{{ json_encode([['type' => 'success', 'message' => session('status')]], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG) }}"
        hidden
    ></div>
@endif

@if ($errors->any())
    <div class="mx-auto w-full max-w-7xl px-4 pt-4 sm:px-6">
        <div class="flash-error rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-500/20 dark:bg-rose-500/8 dark:text-rose-300" role="alert">
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
