@extends('layouts.app')

@section('title', 'Support assistants · '.config('app.name'))

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Support assistants</h1>
        <p class="mt-1 max-w-2xl text-sm text-slate-500 dark:text-slate-500">
            Configure a product-support bot, test customer questions, and publish it on your website. Internal knowledge helps it answer about your product, services, and policies.
        </p>
    </div>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
        {{-- Create Site Card --}}
        <section class="rounded-2xl border border-slate-200/80 bg-white dark:bg-[#0d0f15] p-5 shadow-xs dark:border-white/10">
            <h2 class="text-sm font-bold text-slate-900 dark:text-white">Create support assistant</h2>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-500">
                Give each website its own identity, selected support knowledge, and message quota.
            </p>

            <form method="POST" action="{{ route('widget.store') }}" class="mt-4 grid gap-3.5 sm:grid-cols-2">
                @csrf

                <div class="sm:col-span-2">
                    <label for="site-name" class="block text-xs font-semibold text-slate-600 dark:text-slate-500">Site Name</label>
                    <input
                        id="site-name"
                        name="name"
                        value="{{ old('name') }}"
                        required
                        maxlength="80"
                        placeholder="Acme Help Center"
                        class="mt-1.5 w-full rounded-xl border border-slate-200/80 bg-slate-50/80 px-3.5 py-2 text-xs text-slate-900 focus:border-indigo-500 focus:bg-white dark:focus:bg-white/10 focus:outline-none dark:border-white/10 dark:bg-white/5 dark:text-white"
                    >
                </div>

                <div>
                    <label for="site-domain" class="block text-xs font-semibold text-slate-600 dark:text-slate-500">Domain (Optional)</label>
                    <input
                        id="site-domain"
                        name="domain"
                        value="{{ old('domain') }}"
                        maxlength="190"
                        placeholder="acme.com"
                        class="mt-1.5 w-full rounded-xl border border-slate-200/80 bg-slate-50/80 px-3.5 py-2 text-xs text-slate-900 focus:border-indigo-500 focus:bg-white dark:focus:bg-white/10 focus:outline-none dark:border-white/10 dark:bg-white/5 dark:text-white"
                    >
                </div>

                <div>
                    <label for="site-quota" class="block text-xs font-semibold text-slate-600 dark:text-slate-500">Monthly Message Quota</label>
                    <input
                        id="site-quota"
                        name="monthly_quota"
                        type="number"
                        min="0"
                        max="1000000"
                        value="{{ old('monthly_quota', 1000) }}"
                        class="mt-1.5 w-full rounded-xl border border-slate-200/80 bg-slate-50/80 px-3.5 py-2 text-xs text-slate-900 focus:border-indigo-500 focus:bg-white dark:focus:bg-white/10 focus:outline-none dark:border-white/10 dark:bg-white/5 dark:text-white"
                    >
                </div>

                <div class="sm:col-span-2 pt-2">
                    <button
                        type="submit"
                        @disabled($documents->isEmpty())
                        class="btn btn-primary btn-sm disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        Create assistant
                    </button>

                    @unless ($documents->isEmpty())
                    @else
                        <p class="mt-2 text-xs text-amber-700 dark:text-amber-400">
                            Add at least one processed support source before creating an assistant.
                        </p>
                    @endunless
                </div>
            </form>
        </section>

        {{-- How it works + supported stacks --}}
        <section class="rounded-2xl border border-slate-200/80 bg-white dark:bg-[#0d0f15] p-5 shadow-xs dark:border-white/10">
            <h2 class="text-sm font-bold text-slate-900 dark:text-white">How Embed Works</h2>
            <ol class="mt-3.5 space-y-3 text-xs text-slate-500">
                <li class="flex items-start gap-2.5">
                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-md bg-indigo-50 text-[10px] font-bold text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-400">1</span>
                    <span>Create an assistant, then connect the product and policy knowledge it may use.</span>
                </li>
                <li class="flex items-start gap-2.5">
                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-md bg-indigo-50 text-[10px] font-bold text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-400">2</span>
                    <span>Open <strong class="font-semibold text-slate-700 dark:text-slate-600">Manage</strong> and copy the snippet for your stack. Every tab installs the same widget — only the placement differs.</span>
                </li>
                <li class="flex items-start gap-2.5">
                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-md bg-indigo-50 text-[10px] font-bold text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-400">3</span>
                    <span>Press <strong class="font-semibold text-slate-700 dark:text-slate-600">Verify installation</strong>, then open the live preview and send a real message.</span>
                </li>
            </ol>

            <h3 class="mt-5 text-xs font-bold text-slate-900 dark:text-white">Supported everywhere</h3>
            <ul class="mt-2.5 flex flex-wrap gap-1.5">
                @foreach (['HTML / CSS / JavaScript', 'SPA with any router', 'React', 'Next.js', 'Vue', 'Angular', 'Svelte / SvelteKit', 'WordPress'] as $stack)
                    <li class="rounded-full border border-slate-200 bg-slate-50/80 px-2.5 py-1 text-[11px] font-medium text-slate-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-600">
                        {{ $stack }}
                    </li>
                @endforeach
            </ul>

            <p class="mt-3.5 text-[11px] leading-relaxed text-slate-400 dark:text-slate-500">
                Each option provides setup steps and a copyable snippet tailored to where that technology loads browser scripts. The widget renders inside a shadow root, keeping its styles isolated from your site.
            </p>
        </section>
    </div>

    {{-- Your Sites List --}}
    <div class="mt-6 rounded-2xl border border-slate-200/80 bg-white dark:bg-[#0d0f15] p-5 shadow-xs dark:border-white/10">
        <h2 class="text-sm font-bold text-slate-900 dark:text-white">Your assistants</h2>

        @if ($sites->isEmpty())
            <p class="mt-3 text-xs text-slate-500 dark:text-slate-500">No support assistants configured yet.</p>
        @else
            <ul class="mt-4 space-y-3">
                @foreach ($sites as $site)
                    <li class="rounded-xl border border-slate-200/80 p-4 transition hover:border-slate-300 dark:border-white/10 dark:hover:border-white/20">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="flex min-w-0 items-start gap-3">
                                <x-blobatar :name="$site->bot_name" :fallback="$site->name" :size="36" background="squircle" data-blobatar-follow="true" class="ring-1 ring-indigo-500/15" />
                                <div class="min-w-0">
                                    <a href="{{ route('widget.show', $site) }}" class="truncate text-sm font-bold text-slate-900 transition hover:text-indigo-600 dark:text-white dark:hover:text-indigo-400">
                                        {{ $site->name }}
                                    </a>
                                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-500">
                                        {{ $site->domain ?: 'Any domain' }}
                                        &middot; {{ $site->documents->count() }} {{ \Illuminate\Support\Str::plural('knowledge source', $site->documents->count()) }}
                                        &middot; {{ $site->conversations_count }} {{ \Illuminate\Support\Str::plural('conversation', $site->conversations_count) }}
                                    </p>
                                </div>
                            </div>

                            <div class="flex items-center gap-2">
                                <span class="rounded-full border px-2.5 py-0.5 text-xs font-semibold {{ $site->isLive()
                                    ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-400'
                                    : 'border-slate-200 bg-slate-50 text-slate-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-500' }}">
                                    {{ $site->isLive() ? 'Live' : 'Paused' }}
                                </span>

                                <a
                                    href="{{ route('widget.show', $site) }}"
                                    class="btn btn-secondary btn-sm"
                                >
                                    Manage
                                </a>
                            </div>
                        </div>

                        <p class="mt-2.5 text-xs text-slate-500 dark:text-slate-500">
                            <span class="font-medium text-slate-700 dark:text-slate-600">{{ $site->messagesUsedThisPeriod() }}</span> of {{ number_format($site->monthly_quota) }} visitor messages used this month
                        </p>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection