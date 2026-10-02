@forelse ($conversations as $conversation)
    @php
        $visitorEmail = $conversation->safeVisitorEmail();
        $visitorId = $conversation->safeVisitorId();
    @endphp
    <article data-lead-conversation="{{ $conversation->getKey() }}" class="rounded-xl border border-slate-200 bg-white p-4 sm:p-5 dark:border-white/15 dark:bg-[#0d0f15]">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="break-all text-sm font-bold text-slate-900 dark:text-white">
                    {{ $visitorEmail ?? ($conversation->visitor_email_consent_at ? 'Email unavailable' : 'Email not collected') }}
                </h2>
                <p class="mt-1 break-all text-xs text-slate-600 dark:text-slate-300">
                    Conversation #{{ $conversation->getKey() }} · Session {{ $visitorId ?? 'unavailable' }}
                </p>
                <p class="mt-1 text-xs text-slate-600 dark:text-slate-300">
                    {{ $conversation->created_at?->format('M j, Y H:i T') }} · {{ $site->domain ?: $site->name }} · {{ $conversation->message_count }} messages
                </p>
            </div>
            <span class="rounded-full border border-slate-300 px-2.5 py-1 text-xs font-semibold text-slate-700 dark:border-white/20 dark:text-slate-200">
                Consent {{ $conversation->visitor_email_consent_at ? 'recorded' : 'not recorded' }}
                @if ($conversation->visitor_email_consent_at)
                    · {{ $conversation->visitor_email_consent_at->format('M j, Y H:i T') }}
                @endif
            </span>
        </div>

        <details class="mt-4 rounded-lg border border-slate-200 dark:border-white/15">
            <summary class="cursor-pointer px-3 py-2.5 text-sm font-semibold text-slate-800 dark:text-slate-100">Conversation transcript</summary>
            <ol class="space-y-2 border-t border-slate-200 p-3 dark:border-white/15">
                @forelse ($conversation->messages as $message)
                    <li class="rounded-lg bg-slate-50 p-3 text-sm dark:bg-white/[0.04]">
                        <p class="text-xs font-semibold capitalize text-slate-600 dark:text-slate-300">{{ $message->role->value }}</p>
                        <p class="mt-1 whitespace-pre-wrap break-words leading-relaxed text-slate-900 dark:text-slate-100">{{ $message->safeContent() ?? 'Message preview unavailable.' }}</p>
                    </li>
                @empty
                    <li class="text-sm text-slate-600 dark:text-slate-300">No messages yet.</li>
                @endforelse
            </ol>
        </details>

        <div class="mt-4 flex flex-wrap items-end justify-between gap-3">
            <form method="POST" action="{{ route('widget.leads.update', [$site, $conversation]) }}" class="grid flex-1 gap-2 sm:grid-cols-2 xl:grid-cols-5">
                @csrf
                @method('PATCH')
                <label class="text-xs font-semibold text-slate-700 dark:text-slate-200">Conversation status
                    <select name="status" class="mt-1 block min-h-10 w-full rounded-lg border border-slate-300 bg-white px-2 text-sm text-slate-900 dark:border-white/20 dark:bg-[#0d0f15] dark:text-white">
                        @foreach (['open' => 'Open', 'closed' => 'Closed'] as $value => $label)
                            <option value="{{ $value }}" @selected($conversation->status === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-xs font-semibold text-slate-700 dark:text-slate-200">Classification
                    <select name="classification" class="mt-1 block min-h-10 w-full rounded-lg border border-slate-300 bg-white px-2 text-sm text-slate-900 dark:border-white/20 dark:bg-[#0d0f15] dark:text-white">
                        @foreach (['lead' => 'Lead', 'support' => 'Support'] as $value => $label)
                            <option value="{{ $value }}" @selected($conversation->classification === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-xs font-semibold text-slate-700 dark:text-slate-200">Assigned team member
                    <select name="assigned_user_id" class="mt-1 block min-h-10 w-full rounded-lg border border-slate-300 bg-white px-2 text-sm text-slate-900 dark:border-white/20 dark:bg-[#0d0f15] dark:text-white">
                        <option value="">Unassigned</option>
                        @foreach ($teamMembers as $member)
                            <option value="{{ $member->getKey() }}" @selected($conversation->assigned_user_id === $member->getKey())>{{ $member->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-xs font-semibold text-slate-700 dark:text-slate-200">Follow-up
                    <select name="follow_up_status" class="mt-1 block min-h-10 w-full rounded-lg border border-slate-300 bg-white px-2 text-sm text-slate-900 dark:border-white/20 dark:bg-[#0d0f15] dark:text-white">
                        @foreach (['none' => 'No follow-up', 'pending' => 'Pending', 'contacted' => 'Contacted', 'complete' => 'Complete'] as $value => $label)
                            <option value="{{ $value }}" @selected($conversation->follow_up_status === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <button type="submit" class="btn btn-primary btn-sm min-h-10 self-end">Save</button>
            </form>
            <form method="POST" action="{{ route('widget.leads.destroy', [$site, $conversation]) }}" data-lead-delete>
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger btn-sm min-h-10">Delete visitor data</button>
            </form>
        </div>
    </article>
@empty
    <div class="rounded-xl border border-slate-200 bg-white p-8 text-center dark:border-white/15 dark:bg-[#0d0f15]">
        <h2 class="text-base font-bold text-slate-900 dark:text-white">No visitor conversations found</h2>
        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">New conversations will appear here when visitors use this assistant.</p>
    </div>
@endforelse