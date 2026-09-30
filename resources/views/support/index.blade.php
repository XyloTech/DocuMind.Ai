@extends('layouts.app')

@section('title', 'Human support · '.config('app.name'))

@section('content')
    <div
        class="mx-auto w-full max-w-3xl"
        data-support-page
        data-support-messages-url="{{ route('support.messages') }}"
        data-support-poll-url="{{ route('support.poll') }}"
        data-support-close-url="{{ route('support.close') }}"
    >
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-extrabold tracking-tight text-slate-900 dark:text-white">Talk to a human</h1>
                <p class="mt-1.5 max-w-xl text-sm leading-relaxed text-slate-500 dark:text-slate-400">
                    Ask for a person in any chat and the conversation lands here, one-to-one with the DocuMind
                    support team. Replies arrive on this page — no refresh needed.
                </p>
            </div>

            @if ($conversation?->status->isLive())
                <button type="button" data-support-close class="btn btn-secondary btn-sm">Close conversation</button>
            @endif
        </div>

        @if ($conversation !== null)
            <div class="mt-5 flex flex-wrap items-center gap-2 text-xs">
                <span
                    data-support-status
                    class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 font-semibold text-slate-700 dark:border-white/10 dark:bg-white/5 dark:text-slate-300"
                >{{ $conversation->status->label() }}</span>
                <span data-support-agent class="text-slate-500 dark:text-slate-400">
                    @if ($conversation->agent !== null)
                        Agent: {{ $conversation->agent->name }}
                    @else
                        Waiting for the first available agent.
                    @endif
                </span>
                <span class="text-slate-400">Conversation #{{ $conversation->getKey() }}</span>
            </div>
        @endif

        <div
            data-support-transcript
            class="mt-4 space-y-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-[#0d0f15]"
            aria-live="polite"
        >
            @forelse ($messages as $message)
                <article
                    data-support-message
                    data-id="{{ $message->getKey() }}"
                    @class([
                        'flex',
                        'justify-end' => $message->role === \App\Enums\SupportMessageRole::User,
                        'justify-start' => $message->role !== \App\Enums\SupportMessageRole::User,
                    ])
                >
                    @if ($message->role === \App\Enums\SupportMessageRole::System)
                        <p class="rounded-lg bg-slate-50 px-3 py-1.5 text-center text-[11px] font-medium text-slate-500 dark:bg-white/5 dark:text-slate-400">
                            {{ $message->content }}
                        </p>
                    @else
                        <div
                            @class([
                                'max-w-[85%] rounded-2xl px-4 py-2.5 text-sm leading-relaxed',
                                'bg-indigo-600 text-white' => $message->role === \App\Enums\SupportMessageRole::User,
                                'border border-slate-200 bg-slate-50 text-slate-800 dark:border-white/10 dark:bg-white/5 dark:text-slate-200' => $message->role === \App\Enums\SupportMessageRole::Agent,
                            ])
                        >
                            <p class="mb-1 text-[10px] font-semibold uppercase tracking-wide opacity-70">
                                {{ $message->role === \App\Enums\SupportMessageRole::Agent ? ($message->sender?->name ?? 'Support') : 'You' }}
                                <span aria-hidden="true">·</span>
                                {{ $message->created_at?->diffForHumans() }}
                            </p>
                            <p class="whitespace-pre-wrap break-words">{{ $message->content }}</p>
                        </div>
                    @endif
                </article>
            @empty
                <div data-support-empty class="py-8 text-center">
                    <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">No conversation yet.</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        Write your first message below, or ask for a person inside any document chat.
                    </p>
                </div>
            @endforelse
        </div>

        <form data-support-form method="POST" action="{{ route('support.messages') }}" class="mt-4 flex items-end gap-2">
            @csrf
            <label class="sr-only" for="support-message">Message for the support team</label>
            <textarea
                id="support-message"
                name="message"
                rows="2"
                required
                maxlength="4000"
                placeholder="Describe what you need a hand with…"
                class="min-w-0 flex-1 resize-none rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-600/20 dark:border-white/15 dark:bg-white/5 dark:text-white dark:placeholder:text-slate-500"
            ></textarea>
            <button type="submit" data-support-send class="btn btn-primary">Send</button>
        </form>

        <p data-support-error class="mt-2 hidden text-xs font-medium text-rose-600 dark:text-rose-400"></p>
    </div>

    <script>
        (function () {
            const page = document.querySelector('[data-support-page]');

            if (!page) {
                return;
            }

            const transcript = page.querySelector('[data-support-transcript]');
            const form = page.querySelector('[data-support-form]');
            const input = form.querySelector('textarea');
            const send = page.querySelector('[data-support-send]');
            const statusPill = page.querySelector('[data-support-status]');
            const agentLine = page.querySelector('[data-support-agent]');
            const errorBox = page.querySelector('[data-support-error]');
            const closeButton = page.querySelector('[data-support-close]');
            const csrf = form.querySelector('[name="_token"]').value;

            const headers = {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            };

            let lastId = Number(
                Array.from(transcript.querySelectorAll('[data-support-message]')).reduce(
                    (max, node) => Math.max(max, Number(node.dataset.id)),
                    0,
                ),
            );
            let busy = false;

            function showError(message) {
                errorBox.textContent = message;
                errorBox.classList.toggle('hidden', !message);
            }

            function render(message) {
                if (Number(message.id) <= lastId) {
                    return;
                }

                lastId = Number(message.id);
                transcript.querySelector('[data-support-empty]')?.remove();

                const article = document.createElement('article');
                article.dataset.supportMessage = '';
                article.dataset.id = String(message.id);
                article.className = 'flex ' + (message.role === 'user' ? 'justify-end' : 'justify-start');

                if (message.role === 'system') {
                    const note = document.createElement('p');
                    note.className = 'rounded-lg bg-slate-50 px-3 py-1.5 text-center text-[11px] font-medium text-slate-500 dark:bg-white/5 dark:text-slate-400';
                    note.textContent = message.content;
                    article.append(note);
                } else {
                    const bubble = document.createElement('div');
                    bubble.className =
                        'max-w-[85%] rounded-2xl px-4 py-2.5 text-sm leading-relaxed ' +
                        (message.role === 'user'
                            ? 'bg-indigo-600 text-white'
                            : 'border border-slate-200 bg-slate-50 text-slate-800 dark:border-white/10 dark:bg-white/5 dark:text-slate-200');

                    const meta = document.createElement('p');
                    meta.className = 'mb-1 text-[10px] font-semibold uppercase tracking-wide opacity-70';
                    meta.textContent = message.label;

                    const body = document.createElement('p');
                    body.className = 'whitespace-pre-wrap break-words';
                    body.textContent = message.content;

                    bubble.append(meta, body);
                    article.append(bubble);
                }

                transcript.append(article);
                transcript.scrollTop = transcript.scrollHeight;
            }

            function applyConversation(conversation) {
                if (!conversation) {
                    return;
                }

                if (statusPill) {
                    statusPill.textContent = conversation.status_label;
                }

                if (agentLine) {
                    agentLine.textContent = conversation.agent
                        ? 'Agent: ' + conversation.agent
                        : 'Waiting for the first available agent.';
                }

                if (closeButton) {
                    closeButton.classList.toggle('hidden', !conversation.live);
                }
            }

            function apply(payload) {
                (payload.messages || []).forEach(render);
                applyConversation(payload.conversation);
            }

            async function post(url, body) {
                const response = await fetch(url, {
                    method: 'POST',
                    headers,
                    credentials: 'same-origin',
                    body: JSON.stringify(body),
                });

                const payload = await response.json().catch(() => ({}));

                if (!response.ok) {
                    throw new Error(
                        payload.message ||
                            Object.values(payload.errors || {}).flat()[0] ||
                            'That message could not be sent.',
                    );
                }

                return payload;
            }

            form.addEventListener('submit', async (event) => {
                event.preventDefault();

                const text = input.value.trim();

                if (!text || busy) {
                    return;
                }

                busy = true;
                send.disabled = true;
                showError('');

                try {
                    apply(await post(form.action, { message: text, since: lastId }));
                    input.value = '';
                } catch (error) {
                    showError(error instanceof Error ? error.message : 'That message could not be sent.');
                } finally {
                    busy = false;
                    send.disabled = false;
                    input.focus();
                }
            });

            closeButton?.addEventListener('click', async () => {
                closeButton.disabled = true;

                try {
                    apply(await post(page.dataset.supportCloseUrl, {}));
                } catch (error) {
                    showError(error instanceof Error ? error.message : 'The conversation could not be closed.');
                } finally {
                    closeButton.disabled = false;
                }
            });

            // Replies come from another person, so the page polls while it is
            // open and stays quiet in a background tab.
            setInterval(async () => {
                if (document.hidden || busy) {
                    return;
                }

                try {
                    const response = await fetch(
                        page.dataset.supportPollUrl + '?since=' + lastId,
                        { headers: { Accept: 'application/json' }, credentials: 'same-origin' },
                    );

                    if (response.ok) {
                        apply(await response.json());
                    }
                } catch {
                    // A dropped poll is recovered by the next one.
                }
            }, 3000);
        })();
    </script>
@endsection
