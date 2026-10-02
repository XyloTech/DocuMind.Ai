@php($user = auth()->user())

<a
    href="{{ route('billing.index') }}"
    data-credit-pill
    class="hidden min-w-0 items-center gap-1.5 rounded-full border border-indigo-200/80 bg-indigo-50/80 px-2 py-1 text-[11px] font-semibold text-indigo-700 shadow-xs transition hover:border-indigo-300 hover:bg-indigo-100 sm:inline-flex sm:px-2.5 sm:text-xs dark:border-indigo-500/20 dark:bg-indigo-500/10 dark:text-indigo-300 dark:hover:border-indigo-500/40"
    title="Credits remaining — click to top up"
>
    <span class="relative flex h-2 w-2">
        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-indigo-400 opacity-75"></span>
        <span class="relative inline-flex h-2 w-2 rounded-full bg-indigo-500"></span>
    </span>
    <span class="sm:hidden" data-credits-short>{{ $user->credits }} cr</span>
    <span class="hidden sm:inline" data-credits-count>{{ $user->credits }} {{ \Illuminate\Support\Str::plural('credit', $user->credits) }}</span>
</a>
