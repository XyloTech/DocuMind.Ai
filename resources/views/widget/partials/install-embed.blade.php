{{-- Install snippet UI — kept outside the settings form so copy/verify controls never submit the form. --}}
<section
    data-install
    data-widget-script-url="{{ route('widget.script') }}"
    id="install-embed-code"
    class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-xs dark:border-white/10 dark:bg-[#0d0f15]"
>
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200/80 p-5 dark:border-white/10">
        <div>
            <h2 class="text-sm font-bold text-slate-900 dark:text-white">Install Embed Code</h2>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                One script tag. No build step on your site — paste, verify, and go live.
            </p>
        </div>

        <span
            data-install-status
            @class([
                'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold',
                'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-400' => $widgetBuilt,
                'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300' => ! $widgetBuilt,
            ])
        >
            <span @class(['h-1.5 w-1.5 rounded-full', $widgetBuilt ? 'bg-emerald-500' : 'bg-amber-500'])></span>
            <span>{{ $widgetBuilt ? 'Widget bundle ready' : 'Bundle not built yet' }}</span>
        </span>
    </div>

    @unless ($widgetBuilt)
        <div class="border-b border-amber-200 bg-amber-50 px-5 py-3.5 text-xs text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300">
            <strong class="font-bold">The widget bundle has not been built yet.</strong>
            Snippets below will 404 until you run
            <code class="rounded bg-amber-100 px-1 py-0.5 font-mono text-[11px] dark:bg-amber-500/20">npm run build</code>
            (builds the app and <code class="rounded bg-amber-100 px-1 py-0.5 font-mono text-[11px] dark:bg-amber-500/20">public/build/widget.js</code>).
        </div>
    @endunless

    <div class="p-5">
        <div class="flex items-center gap-2">
            <button
                type="button"
                data-snippet-scroll="previous"
                aria-label="Scroll installation options left"
                title="Previous installation options"
                aria-controls="snippet-tablist"
                disabled
                class="btn btn-secondary btn-icon btn-sm h-9 w-9 shrink-0 disabled:cursor-not-allowed disabled:opacity-40"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="m15 18-6-6 6-6" />
                </svg>
            </button>

            <div class="dm-snippet-scroll relative min-w-0 flex-1">
                <div
                    id="snippet-tablist"
                    data-snippet-tabs
                    class="dm-chip-row flex snap-x snap-proximity gap-1.5 overflow-x-auto scroll-smooth px-1.5 py-1.5 touch-pan-x motion-reduce:scroll-auto [-ms-overflow-style:none] [scrollbar-width:none]"
                    role="tablist"
                    aria-label="Installation method"
                    aria-orientation="horizontal"
                >
                    @foreach ($snippets as $key => $frame)
                        <button
                            type="button"
                            role="tab"
                            id="snippet-tab-{{ $key }}"
                            data-snippet-tab="{{ $key }}"
                            tabindex="{{ $loop->first ? '0' : '-1' }}"
                            aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                            aria-controls="snippet-panel-{{ $key }}"
                            @class([
                                'dm-chip shrink-0 snap-start scroll-mx-1.5 rounded-full px-3.5 py-1.5 text-xs font-semibold tracking-tight transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-[#0d0f15]',
                                'bg-slate-900 text-white shadow-xs dark:bg-white dark:text-black' => $loop->first,
                                'border border-slate-200 bg-white text-slate-600 hover:border-indigo-300 hover:text-indigo-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-400 dark:hover:border-indigo-400 dark:hover:text-indigo-300' => ! $loop->first,
                            ])
                        >{{ $frame['label'] }}</button>
                    @endforeach
                </div>

                {{-- A pill sliced mid-word by the strip's edge reads as a bug, so the
                     edge fades to the card surface while there is more to scroll to. --}}
                <span class="dm-snippet-fade dm-snippet-fade--start" data-snippet-fade="previous" aria-hidden="true"></span>
                <span class="dm-snippet-fade dm-snippet-fade--end" data-snippet-fade="next" aria-hidden="true"></span>
            </div>

            <button
                type="button"
                data-snippet-scroll="next"
                aria-label="Scroll installation options right"
                title="Next installation options"
                aria-controls="snippet-tablist"
                class="btn btn-secondary btn-icon btn-sm h-9 w-9 shrink-0 disabled:cursor-not-allowed disabled:opacity-40"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="m9 18 6-6-6-6" />
                </svg>
            </button>
        </div>

        @foreach ($snippets as $key => $frame)
            <div
                id="snippet-panel-{{ $key }}"
                role="tabpanel"
                tabindex="0"
                aria-labelledby="snippet-tab-{{ $key }}"
                data-snippet-panel="{{ $key }}"
                @class(['hidden' => ! $loop->first, 'mt-1.5'])
            >
                <p class="mb-2.5 text-xs text-slate-500 dark:text-slate-400">{{ $frame['hint'] }}</p>

                @if (! empty($frame['steps']))
                    <ol class="mb-3 space-y-1.5">
                        @foreach ($frame['steps'] as $index => $step)
                            <li class="flex gap-2 text-[11px] leading-relaxed text-slate-600 dark:text-slate-400">
                                <span class="mt-px inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-[9px] font-bold text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300">
                                    {{ $index + 1 }}
                                </span>
                                <span>{{ $step }}</span>
                            </li>
                        @endforeach
                    </ol>
                @endif

                <div class="flex items-start gap-2">
                    <pre
                        data-copy-source
                        data-snippet-source="{{ $key }}"
                        class="dm-code-scroll max-h-72 flex-1 overflow-auto whitespace-pre-wrap break-all rounded-xl border border-slate-200 bg-slate-50/80 p-3.5 font-mono text-xs leading-relaxed text-slate-800 dark:border-white/10 dark:bg-black/60 dark:text-slate-200"
                    ><code>{{ $frame['code'] }}</code></pre>

                    <button
                        type="button"
                        data-copy-target
                        data-copy-for="{{ $key }}"
                        class="dm-icon-button inline-flex shrink-0 items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-xs font-semibold text-slate-700 shadow-2xs hover:border-indigo-300 hover:bg-indigo-50/60 hover:text-indigo-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-200 dark:hover:border-indigo-400 dark:hover:bg-indigo-500/10 dark:hover:text-indigo-300"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect width="14" height="14" x="8" y="8" rx="2" ry="2"/>
                            <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>
                        </svg>
                        <span>Copy</span>
                    </button>
                </div>
            </div>
        @endforeach

        <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-slate-200/80 pt-4 dark:border-white/10">
            <button
                type="button"
                data-verify-install
                class="btn btn-secondary btn-sm inline-flex items-center gap-1.5"
            >
                <svg class="h-3.5 w-3.5 text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <path d="m9 11 3 3L22 4"/>
                </svg>
                <span>Verify installation</span>
            </button>

            <a
                href="{{ route('widget.preview', $site) }}"
                target="_blank"
                rel="noopener"
                class="btn btn-secondary btn-sm inline-flex items-center gap-1.5"
            >
                <svg class="h-3.5 w-3.5 text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
                <span>Open live preview</span>
            </a>
        </div>

        <details class="group mt-5 rounded-xl border border-slate-200/80 bg-slate-50/50 dark:border-white/10 dark:bg-white/[0.03]">
            <summary class="cursor-pointer list-none px-4 py-3 text-xs font-bold text-slate-800 marker:content-none dark:text-slate-200">
                <span class="inline-flex items-center gap-2">
                    <svg class="h-3.5 w-3.5 text-indigo-500 transition group-open:rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="m9 18 6-6-6-6"/>
                    </svg>
                    Security, domains &amp; configuration
                </span>
            </summary>
            <dl class="space-y-3 border-t border-slate-200/80 px-4 py-3 text-[11px] leading-relaxed text-slate-600 dark:border-white/10 dark:text-slate-400">
                <div>
                    <dt class="font-bold text-slate-800 dark:text-slate-200">Origin allowlist</dt>
                    <dd class="mt-0.5">
                        @if ($site->domain)
                            Only pages served from <strong class="font-semibold text-slate-700 dark:text-slate-300">{{ $site->domain }}</strong> may call the widget API. Other origins receive a CORS error.
                        @else
                            No domain is set — the widget accepts requests from any origin. Set <em>Authorized Domain</em> below to lock it down in production.
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="font-bold text-slate-800 dark:text-slate-200">Responsive behaviour</dt>
                    <dd class="mt-0.5">The launcher stays within the viewport, opens a full-height panel on narrow screens, and moves keyboard focus into the message composer when chat opens.</dd>
                </div>
                <div>
                    <dt class="font-bold text-slate-800 dark:text-slate-200">Configuration</dt>
                    <dd class="mt-0.5">
                        Assistant name, logo, theme, launcher icon, accent, greeting, placement, quota, and selected knowledge are controlled from this page.
                        The script URL is always <code class="rounded bg-slate-200/80 px-1 font-mono dark:bg-white/10">{{ $origin }}/widget.js</code> with <code class="rounded bg-slate-200/80 px-1 font-mono dark:bg-white/10">data-site-key="{{ $site->site_key }}"</code>.
                    </dd>
                </div>
            </dl>
        </details>
    </div>
</section>
