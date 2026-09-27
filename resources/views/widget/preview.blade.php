<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" sizes="500x500" href="{{ asset('favicon.png') }}">
    <title>Widget preview · {{ $site->name }}</title>

    {{-- Captured before the bundle loads so "never executed" and "executed and
         threw" can be told apart in the status pill below. --}}
    <script>
        window.__widgetErrors = [];
        window.addEventListener('error', function (e) {
            var where = e.filename ? ' @' + e.filename.split('/').pop() + ':' + (e.lineno || 0) : '';
            window.__widgetErrors.push((e.message || 'resource failed') + where);
        }, true);
    </script>

    {{-- The real bundle, loaded the way a customer's site loads it. `?v=` is a
         fingerprint of the current build, so this page can never be shown a
         copy the browser is still holding from before a rebuild. --}}
    <script src="{{ $scriptUrl }}" data-site-key="{{ $siteKey }}" defer
            onerror="window.__widgetScriptFailed = true"></script>

    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 2rem 1rem;
            font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            background:
                radial-gradient(60rem 40rem at 15% -10%, rgb(99 102 241 / 0.14), transparent 60%),
                radial-gradient(50rem 35rem at 110% 110%, rgb(168 85 247 / 0.12), transparent 60%),
                #f8fafc;
            color: #0f172a;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #05060a; color: #e2e8f0; }
        }
        .card {
            width: 100%;
            max-width: 34rem;
            border-radius: 1.5rem;
            border: 1px solid rgb(15 23 42 / 0.08);
            background: rgb(255 255 255 / 0.75);
            backdrop-filter: blur(12px);
            padding: 2rem;
            box-shadow: 0 24px 60px -30px rgb(15 23 42 / 0.35);
        }
        @media (prefers-color-scheme: dark) {
            .card { border-color: rgb(255 255 255 / 0.08); background: rgb(13 15 21 / 0.7); }
        }
        h1 { margin: 0 0 .35rem; font-size: 1.25rem; font-weight: 800; letter-spacing: -0.02em; }
        p { margin: 0; font-size: .875rem; line-height: 1.6; opacity: .75; }
        .status {
            display: inline-flex; align-items: center; gap: .5rem;
            margin-top: 1.25rem; padding: .4rem .75rem;
            border-radius: 9999px; font-size: .75rem; font-weight: 600;
            background: rgb(245 158 11 / .12); color: #b45309;
        }
        .status.ok { background: rgb(16 185 129 / .12); color: #047857; }
        .status.bad { background: rgb(244 63 94 / .12); color: #be123c; }
        .status[data-state="loading"] { background: rgb(99 102 241 / .12); color: #4338ca; }
        @media (prefers-color-scheme: dark) {
            .status.ok { color: #34d399; }
            .status.bad { color: #fb7185; }
            .status[data-state="loading"] { color: #a5b4fc; }
        }
        .dot { width: .4rem; height: .4rem; border-radius: 9999px; background: currentColor; }
        code {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: .75rem; padding: .1rem .3rem; border-radius: .35rem;
            background: rgb(15 23 42 / .06);
        }
        @media (prefers-color-scheme: dark) { code { background: rgb(255 255 255 / .08); } }
        .hint { margin-top: 1.5rem; font-size: .75rem; opacity: .6; }
    </style>
</head>
<body>
    <main class="card">
        <h1>{{ $site->name }} — widget preview</h1>
        <p>
            This page loads the real bundle from <code>{{ $scriptUrl }}</code>,
            the way a customer's site loads it. Open the bubble in the corner and
            send a message.
        </p>

        <span class="status" data-state="loading" data-status>
            <span class="dot"></span>
            <span data-status-text>Loading widget…</span>
        </span>

        <p class="hint" data-hint></p>
    </main>

    <script>
        (function () {
            var status = document.querySelector('[data-status]');
            var text = document.querySelector('[data-status-text]');
            var hint = document.querySelector('[data-hint]');
            var paused = @json(! $isLive);

            function set(state, message, detail) {
                status.dataset.state = state;
                status.classList.remove('ok', 'bad');
                if (state === 'ok') status.classList.add('ok');
                if (state === 'bad') status.classList.add('bad');
                text.textContent = message;
                hint.textContent = detail || '';
            }

            {{-- Message rendered only when paused, so the assertion that a
                 paused site warns stays meaningful. --}}
            @if (! $isLive)
                set('bad', 'Widget is not live', 'Enable the widget and link at least one processed document on the settings page.');
            @endif

            // The bundle publishes this API, which also proves the script
            // executed rather than merely being present in the DOM. Each way
            // that can fail is reported on its own, because "not built",
            // "blocked by the browser" and "threw on execute" need different fixes.
            function report() {
                if (paused) {
                    return;
                }

                if (window.DocuMindWidget) {
                    set('ok', 'Widget loaded', 'The bubble is rendered by the real bundle. Send a message to verify the API end to end.');
                    return;
                }

                if (window.__widgetScriptFailed) {
                    set('bad', 'Bundle request failed', 'The browser could not fetch the bundle at all. Check the network tab for a blocked or failed request to widget.js — an ad blocker or a stopped server is the usual cause.');
                    return;
                }

                var errs = (window.__widgetErrors || []).slice(0, 3).join(' · ');
                set('bad', 'Widget did not load', errs
                    ? 'The bundle was fetched but threw while executing: ' + errs
                    : 'Run "npm run build" so public/build/widget.js exists, then hard-refresh.');
            }

            var deadline = setTimeout(report, 6000);

            window.addEventListener('load', function () {
                clearTimeout(deadline);
                report();
            });
        })();
    </script>
</body>
</html>
