@extends('layouts.app')

@section('title', 'Admin')

@section('content')
    @php
        $tabs = [
            ['key' => 'overview', 'label' => 'Overview'],
            ['key' => 'accounts', 'label' => 'Accounts', 'ability' => 'view-admin-accounts'],
            ['key' => 'knowledge', 'label' => 'Knowledge', 'ability' => 'view-admin-support-data'],
            ['key' => 'conversations', 'label' => 'Conversations', 'ability' => 'view-admin-support-data'],
            ['key' => 'support', 'label' => 'Support inbox', 'ability' => 'view-admin-support-data'],
            ['key' => 'widgets', 'label' => 'Widgets', 'ability' => 'view-admin-support-data'],
            ['key' => 'models', 'label' => 'Model access', 'ability' => 'manage-admin'],
            ['key' => 'audit', 'label' => 'Audit log', 'ability' => 'view-admin-audit'],
        ];
    @endphp

    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Platform operations</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Account health, support knowledge, assistant activity, and model access.</p>
        </div>
        <span class="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">
            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
            {{ $modelName }} · {{ ucfirst($modelDriver) }}
        </span>
    </div>

    <nav aria-label="Admin sections" class="mb-6 flex gap-1 overflow-x-auto border-b border-slate-200 dark:border-white/10">
        @foreach ($tabs as $tab)
            @if (! isset($tab['ability']) || auth()->user()->can($tab['ability']))
                <a
                    href="{{ route('admin.dashboard', ['tab' => $tab['key']]) }}"
                    @class([
                        'shrink-0 border-b-2 px-3 py-2.5 text-sm font-semibold transition',
                        'border-slate-900 text-slate-900 dark:border-white dark:text-white' => $activeTab === $tab['key'],
                        'border-transparent text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white' => $activeTab !== $tab['key'],
                    ])
                    @if ($activeTab === $tab['key']) aria-current="page" @endif
                >{{ $tab['label'] }}</a>
            @endif
        @endforeach
    </nav>

    @if ($activeTab === 'overview')
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($stats as $stat)
                <section class="rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-[#0d0f15]">
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ $stat['label'] }}</p>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-slate-900 dark:text-white">{{ number_format($stat['value']) }}</p>
                </section>
            @endforeach
        </div>

        <div class="mt-5 grid gap-4 lg:grid-cols-2">
            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-white/10 dark:bg-[#0d0f15]">
                <h2 class="text-sm font-bold text-slate-900 dark:text-white">Model access</h2>
                <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-3 text-xs">
                    <dt class="text-slate-500 dark:text-slate-400">Driver</dt>
                    <dd class="text-right font-semibold text-slate-800 dark:text-slate-200">{{ ucfirst($modelDriver) }}</dd>
                    <dt class="text-slate-500 dark:text-slate-400">Display model</dt>
                    <dd class="text-right font-semibold text-slate-800 dark:text-slate-200">{{ $modelName }}</dd>
                    <dt class="text-slate-500 dark:text-slate-400">Provider credential</dt>
                    <dd class="text-right font-semibold text-slate-800 dark:text-slate-200">{{ $openAiKeyConfigured ? 'Configured' : 'Not configured' }}</dd>
                </dl>
                @can('manage-admin')
                    <a href="{{ route('admin.dashboard', ['tab' => 'models']) }}" class="mt-4 inline-flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">Configure model access <span aria-hidden="true">→</span></a>
                @endcan
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-white/10 dark:bg-[#0d0f15]">
                <h2 class="text-sm font-bold text-slate-900 dark:text-white">Workspace and plans</h2>
                <p class="mt-2 text-xs leading-relaxed text-slate-500 dark:text-slate-400">This installation currently treats each customer account as its own workspace and uses credits instead of subscription plans. No plan or multi-member workspace records are configured.</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <a href="{{ route('admin.dashboard', ['tab' => 'knowledge']) }}" class="btn btn-secondary btn-sm">Knowledge activity</a>
                    <a href="{{ route('admin.dashboard', ['tab' => 'widgets']) }}" class="btn btn-secondary btn-sm">Widget installs</a>
                </div>
            </div>
        </div>
    @elseif ($activeTab === 'accounts')
        <section class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-white/10 dark:bg-[#0d0f15]">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-4 dark:border-white/10">
                <div>
                    <h2 class="text-sm font-bold text-slate-900 dark:text-white">Account directory</h2>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Support sees account IDs and masked contact details; account changes require an administrator.</p>
                </div>
                @can('manage-admin')
                    <a href="{{ route('admin.users.export') }}" class="btn btn-secondary btn-sm">Export masked CSV</a>
                @endcan
            </div>

            <form method="GET" action="{{ route('admin.dashboard') }}" class="grid gap-2 border-b border-slate-200 p-4 sm:grid-cols-[minmax(12rem,1fr)_10rem_10rem_10rem_8rem_auto] dark:border-white/10">
                <input type="hidden" name="tab" value="accounts">
                <label class="sr-only" for="account-search">Search accounts</label>
                <input id="account-search" name="search" value="{{ $search }}" placeholder="{{ $canManage ? 'Search by name, email, or ID' : 'Search by account ID' }}" class="min-w-0 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none dark:border-white/10 dark:bg-white/5 dark:text-white dark:placeholder:text-slate-500">
                <select name="role" aria-label="Filter by role" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 dark:border-white/10 dark:bg-[#0d0f15] dark:text-white">
                    <option value="">All roles</option>
                    @foreach ($roleOptions as $role)
                        <option value="{{ $role->value }}" @selected($roleFilter === $role->value)>{{ $role->label() }}</option>
                    @endforeach
                </select>
                <select name="status" aria-label="Filter by account status" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 dark:border-white/10 dark:bg-[#0d0f15] dark:text-white">
                    <option value="">All statuses</option>
                    <option value="active" @selected($statusFilter === 'active')>Active</option>
                    <option value="suspended" @selected($statusFilter === 'suspended')>Suspended</option>
                </select>
                <select name="sort" aria-label="Sort accounts" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 dark:border-white/10 dark:bg-[#0d0f15] dark:text-white">
                    <option value="created_at" @selected(request('sort', 'created_at') === 'created_at')>Recently added</option>
                    @if ($canManage)
                        <option value="name" @selected(request('sort') === 'name')>Name</option>
                        <option value="credits" @selected(request('sort') === 'credits')>Credits</option>
                    @endif
                    <option value="last_login_at" @selected(request('sort') === 'last_login_at')>Last sign-in</option>
                </select>
                <select name="direction" aria-label="Sort direction" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 dark:border-white/10 dark:bg-[#0d0f15] dark:text-white">
                    <option value="desc" @selected(request('direction', 'desc') === 'desc')>Descending</option>
                    <option value="asc" @selected(request('direction') === 'asc')>Ascending</option>
                </select>
                <button type="submit" class="btn btn-secondary">Filter</button>
            </form>

            @can('manage-admin')
                <details class="border-b border-slate-200 p-4 dark:border-white/10">
                    <summary class="cursor-pointer text-sm font-semibold text-slate-800 dark:text-slate-200">Create account</summary>
                    <form method="POST" action="{{ route('admin.users.store') }}" class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        @csrf
                        <input name="name" required maxlength="255" placeholder="Name" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                        <input name="email" type="email" required maxlength="255" placeholder="Email" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                        <select name="role" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-[#0d0f15] dark:text-white">
                            @foreach ($roleOptions as $role)
                                <option value="{{ $role->value }}">{{ $role->label() }}</option>
                            @endforeach
                        </select>
                        <input name="credits" type="number" min="0" max="1000000" value="{{ config('billing.welcome_credits') }}" required aria-label="Starting credits" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                        <input name="reason" required minlength="8" maxlength="255" placeholder="Reason for account creation" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-white/5 dark:text-white sm:col-span-2 lg:col-span-4">
                        <button type="submit" data-admin-confirm="Create account and send a password setup link?" class="btn btn-primary btn-sm">Create and invite</button>
                    </form>
                </details>
            @endcan

            <div class="overflow-x-auto">
                <table class="w-full min-w-[850px] text-left text-xs">
                    <thead class="bg-slate-50 text-[10px] font-semibold uppercase text-slate-500 dark:bg-white/[0.03] dark:text-slate-400">
                        <tr><th class="px-4 py-3">Account</th><th class="px-4 py-3">Role</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Credits</th><th class="px-4 py-3">Last sign-in</th><th class="px-4 py-3">Joined</th><th class="px-4 py-3">Actions</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                        @forelse ($accounts as $account)
                            <tr>
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-slate-800 dark:text-slate-200">{{ $account->admin_display_name }}</div>
                                    <div class="mt-0.5 text-slate-500 dark:text-slate-400">{{ $account->admin_display_email }}</div>
                                </td>
                                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $account->role->label() }}</td>
                                <td class="px-4 py-3"><span class="rounded-full px-2 py-1 {{ $account->is_banned ? 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' }}">{{ $account->is_banned ? 'Suspended' : 'Active' }}</span></td>
                                <td class="px-4 py-3 tabular-nums text-slate-700 dark:text-slate-200">{{ $canManage ? number_format($account->credits) : 'Restricted' }}</td>
                                <td class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ $account->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                                <td class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ $account->created_at?->format('Y-m-d') }}</td>
                                <td class="px-4 py-3">
                                    @can('manage-admin')
                                        @unless ($account->is(auth()->user()))
                                            <details>
                                                <summary class="cursor-pointer font-semibold text-indigo-600 dark:text-indigo-400">Manage</summary>
                                                <div class="absolute z-20 mt-2 w-72 rounded-xl border border-slate-200 bg-white p-3 shadow-xl dark:border-white/10 dark:bg-[#0d0f15]">
                                                    <form method="POST" action="{{ route('admin.users.update', $account) }}" class="space-y-2" data-admin-confirm="Save this account change? The reason will be added to the audit log.">
                                                        @csrf
                                                        @method('PUT')
                                                        <label class="block text-[11px] font-medium text-slate-600 dark:text-slate-300">Role
                                                            <select name="role" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs dark:border-white/10 dark:bg-[#0d0f15] dark:text-white">
                                                                @foreach ($roleOptions as $role)
                                                                    <option value="{{ $role->value }}" @selected($account->role === $role)>{{ $role->label() }}</option>
                                                                @endforeach
                                                            </select>
                                                        </label>
                                                        <label class="block text-[11px] font-medium text-slate-600 dark:text-slate-300">Status
                                                            <select name="status" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs dark:border-white/10 dark:bg-[#0d0f15] dark:text-white">
                                                                <option value="active" @selected(! $account->is_banned)>Active</option>
                                                                <option value="suspended" @selected($account->is_banned)>Suspended</option>
                                                            </select>
                                                        </label>
                                                        <label class="block text-[11px] font-medium text-slate-600 dark:text-slate-300">Credits
                                                            <input name="credits" type="number" min="0" max="1000000" value="{{ $account->credits }}" required class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs dark:border-white/10 dark:bg-white/5 dark:text-white">
                                                        </label>
                                                        <input name="reason" required minlength="8" maxlength="255" placeholder="Required reason" class="w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs dark:border-white/10 dark:bg-white/5 dark:text-white">
                                                        <button type="submit" class="btn btn-primary btn-sm w-full">Save and log</button>
                                                    </form>
                                                </div>
                                            </details>
                                        @endunless
                                    @else
                                        <span class="text-slate-400">Read only</span>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">No accounts match these filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 px-4 py-3 dark:border-white/10">{{ $accounts->links() }}</div>
        </section>
    @elseif ($activeTab === 'knowledge')
        <section class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-white/10 dark:bg-[#0d0f15]">
            <table class="w-full min-w-[700px] text-left text-xs">
                <thead class="bg-slate-50 text-[10px] font-semibold uppercase text-slate-500 dark:bg-white/[0.03] dark:text-slate-400"><tr><th class="px-4 py-3">Source</th><th class="px-4 py-3">Account</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Sections</th><th class="px-4 py-3">Size</th><th class="px-4 py-3">Activity</th></tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                    @forelse ($knowledge as $source)
                        <tr><td class="px-4 py-3 font-medium text-slate-800 dark:text-slate-200"><a class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400" href="{{ route('admin.documents.show', $source) }}">{{ $source->filename }}</a></td><td class="px-4 py-3 text-slate-500">Account #{{ $source->user_id }}</td><td class="px-4 py-3">{{ $source->status->label() }}</td><td class="px-4 py-3 tabular-nums">{{ number_format($source->chunk_count) }}</td><td class="px-4 py-3">{{ \Illuminate\Support\Number::fileSize($source->size_bytes) }}</td><td class="px-4 py-3 text-slate-500">{{ $source->processed_at?->diffForHumans() ?? $source->created_at?->diffForHumans() }}</td></tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">No knowledge sources found.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="border-t border-slate-200 px-4 py-3 dark:border-white/10">{{ $knowledge->links() }}</div>
        </section>
    @elseif ($activeTab === 'conversations')
        <div class="space-y-5">
            <section class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-white/10 dark:bg-[#0d0f15]">
                <div class="border-b border-slate-200 px-4 py-3 dark:border-white/10"><h2 class="text-sm font-bold text-slate-900 dark:text-white">Signed-in support tests</h2><p class="mt-1 text-xs text-slate-500">Conversation text and titles are not exposed in the operations console.</p></div>
                <table class="w-full min-w-[620px] text-left text-xs"><thead class="bg-slate-50 text-[10px] uppercase text-slate-500 dark:bg-white/[0.03]"><tr><th class="px-4 py-3">Conversation</th><th class="px-4 py-3">Account</th><th class="px-4 py-3">Messages</th><th class="px-4 py-3">Last activity</th></tr></thead><tbody class="divide-y divide-slate-100 dark:divide-white/5">
                    @forelse ($chats as $chat)<tr><td class="px-4 py-3 font-medium">Conversation #{{ $chat->id }}</td><td class="px-4 py-3">Account #{{ $chat->user_id }}</td><td class="px-4 py-3 tabular-nums">{{ $chat->messages_count }}</td><td class="px-4 py-3 text-slate-500">{{ $chat->last_message_at?->diffForHumans() ?? 'No activity' }}</td></tr>@empty<tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">No support tests found.</td></tr>@endforelse
                </tbody></table><div class="border-t border-slate-200 px-4 py-3 dark:border-white/10">{{ $chats->links() }}</div>
            </section>
            <section class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-white/10 dark:bg-[#0d0f15]">
                <div class="border-b border-slate-200 px-4 py-3 dark:border-white/10"><h2 class="text-sm font-bold text-slate-900 dark:text-white">Website support activity</h2></div>
                <table class="w-full min-w-[620px] text-left text-xs"><thead class="bg-slate-50 text-[10px] uppercase text-slate-500 dark:bg-white/[0.03]"><tr><th class="px-4 py-3">Conversation</th><th class="px-4 py-3">Assistant</th><th class="px-4 py-3">Messages</th><th class="px-4 py-3">Last activity</th></tr></thead><tbody class="divide-y divide-slate-100 dark:divide-white/5">
                    @forelse ($widgetConversations as $conversation)<tr><td class="px-4 py-3 font-medium">Visitor conversation #{{ $conversation->id }}</td><td class="px-4 py-3">{{ $conversation->site?->name ?? 'Removed assistant' }}</td><td class="px-4 py-3 tabular-nums">{{ $conversation->messages_count }}</td><td class="px-4 py-3 text-slate-500">{{ $conversation->updated_at?->diffForHumans() }}</td></tr>@empty<tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">No visitor conversations found.</td></tr>@endforelse
                </tbody></table><div class="border-t border-slate-200 px-4 py-3 dark:border-white/10">{{ $widgetConversations->links() }}</div>
            </section>
        </div>
    @elseif ($activeTab === 'support')
        <section class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-white/10 dark:bg-[#0d0f15]">
            <div class="border-b border-slate-200 px-4 py-3 dark:border-white/10">
                <h2 class="text-sm font-bold text-slate-900 dark:text-white">Human support requests</h2>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">One-to-one conversations opened from the dashboard chat. Open a conversation to read the transcript and reply.</p>
            </div>
            <table class="w-full min-w-[760px] text-left text-xs">
                <thead class="bg-slate-50 text-[10px] uppercase text-slate-500 dark:bg-white/[0.03]">
                    <tr><th class="px-4 py-3">Conversation</th><th class="px-4 py-3">Account</th><th class="px-4 py-3">Agent</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Messages</th><th class="px-4 py-3">Last activity</th><th class="px-4 py-3">Action</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                    @forelse ($supportConversations as $support)
                        <tr>
                            <td class="px-4 py-3 font-medium">Support conversation #{{ $support->id }}</td>
                            <td class="px-4 py-3">Account #{{ $support->user_id }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ $support->agent?->name ?? 'Unassigned' }}</td>
                            <td class="px-4 py-3">{{ $support->status->label() }}</td>
                            <td class="px-4 py-3 tabular-nums">{{ $support->messages_count }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ $support->last_message_at?->diffForHumans() ?? 'No activity' }}</td>
                            <td class="px-4 py-3"><a href="{{ route('admin.support.show', $support) }}" class="font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">Open</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">No human support requests yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="border-t border-slate-200 px-4 py-3 dark:border-white/10">{{ $supportConversations->links() }}</div>
        </section>
    @elseif ($activeTab === 'widgets')
        <section class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-white/10 dark:bg-[#0d0f15]">
            <table class="w-full min-w-[800px] text-left text-xs"><thead class="bg-slate-50 text-[10px] uppercase text-slate-500 dark:bg-white/[0.03]"><tr><th class="px-4 py-3">Assistant</th><th class="px-4 py-3">Account</th><th class="px-4 py-3">Domain</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Knowledge</th><th class="px-4 py-3">Conversations</th><th class="px-4 py-3">Quota</th></tr></thead><tbody class="divide-y divide-slate-100 dark:divide-white/5">
                @forelse ($sites as $site)<tr><td class="px-4 py-3"><div class="font-semibold">{{ $site->name }}</div><div class="mt-0.5 text-slate-500">{{ $site->bot_name }}</div></td><td class="px-4 py-3">Account #{{ $site->user_id }}</td><td class="px-4 py-3 text-slate-500">{{ $site->domain ?: 'Unrestricted' }}</td><td class="px-4 py-3">{{ $site->enabled ? 'Enabled' : 'Paused' }}</td><td class="px-4 py-3 tabular-nums">{{ $site->documents_count }}</td><td class="px-4 py-3 tabular-nums">{{ $site->conversations_count }}</td><td class="px-4 py-3 tabular-nums">{{ number_format($site->messages_used) }} / {{ number_format($site->monthly_quota) }}</td></tr>@empty<tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">No website assistants found.</td></tr>@endforelse
            </tbody></table><div class="border-t border-slate-200 px-4 py-3 dark:border-white/10">{{ $sites->links() }}</div>
        </section>
    @elseif ($activeTab === 'models')
        <section class="max-w-2xl rounded-xl border border-slate-200 bg-white p-5 dark:border-white/10 dark:bg-[#0d0f15]">
            <h2 class="text-sm font-bold text-slate-900 dark:text-white">Model access</h2>
            <p class="mt-1 text-xs leading-relaxed text-slate-500 dark:text-slate-400">Provider keys are encrypted at rest. Existing credentials are never displayed or included in exports.</p>
            <form method="POST" action="{{ route('admin.model-settings.update') }}" class="mt-4 space-y-4" data-admin-confirm="Update the platform's model access settings? The change will be logged.">
                @csrf
                <div>
                    <label for="ai_driver" class="block text-xs font-semibold text-slate-700 dark:text-slate-300">AI driver</label>
                    <select id="ai_driver" name="ai_driver" class="mt-1.5 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                        @foreach (['local' => 'Local model service', 'openai' => 'OpenAI-compatible API', 'fake' => 'Offline demo'] as $value => $label)
                            <option value="{{ $value }}" @selected($modelDriver === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="openai_api_key" class="block text-xs font-semibold text-slate-700 dark:text-slate-300">Provider API key</label>
                    <input id="openai_api_key" name="openai_api_key" type="password" autocomplete="new-password" maxlength="500" placeholder="{{ $openAiKeyConfigured ? 'Key stored; leave blank to keep it' : 'No key stored' }}" class="mt-1.5 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                    <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">{{ $openAiKeyConfigured ? 'A credential is configured. Enter a new value only to rotate it.' : 'A key is required for the OpenAI-compatible driver.' }}</p>
                </div>
                <div>
                    <label for="model-reason" class="block text-xs font-semibold text-slate-700 dark:text-slate-300">Reason</label>
                    <input id="model-reason" name="reason" required minlength="8" maxlength="255" class="mt-1.5 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Save model access</button>
            </form>
        </section>
    @elseif ($activeTab === 'audit')
        <section class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-white/10 dark:bg-[#0d0f15]">
            <table class="w-full min-w-[750px] text-left text-xs"><thead class="bg-slate-50 text-[10px] uppercase text-slate-500 dark:bg-white/[0.03]"><tr><th class="px-4 py-3">Time</th><th class="px-4 py-3">Actor</th><th class="px-4 py-3">Action</th><th class="px-4 py-3">Account</th><th class="px-4 py-3">Reason</th></tr></thead><tbody class="divide-y divide-slate-100 dark:divide-white/5">
                @forelse ($auditLogs as $log)<tr><td class="px-4 py-3 text-slate-500">{{ $log->created_at?->format('Y-m-d H:i:s') }}</td><td class="px-4 py-3">{{ $log->actor?->name ?? 'Deleted staff account' }}</td><td class="px-4 py-3 font-medium">{{ $log->summary }}</td><td class="px-4 py-3">{{ $log->subject_user_id ? 'Account #'.$log->subject_user_id : 'Platform' }}</td><td class="max-w-sm px-4 py-3 text-slate-500">{{ $log->details['reason'] ?? '—' }}</td></tr>@empty<tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">No administrative actions logged yet.</td></tr>@endforelse
            </tbody></table><div class="border-t border-slate-200 px-4 py-3 dark:border-white/10">{{ $auditLogs->links() }}</div>
        </section>
    @endif
@endsection
