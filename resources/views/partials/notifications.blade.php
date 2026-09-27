{{-- Header notification bell: badge, dropdown feed and the endpoints the
     poller talks to. The dropdown list is rendered client-side from
     /notifications/feed; the full centre is a normal server-rendered page. --}}
<div
    class="relative"
    data-notifications
    data-notifications-base="{{ route('notifications.index') }}"
    data-notifications-browser="{{ auth()->user()->notificationPreferences()['browser'] ? '1' : '0' }}"
    data-notifications-initial="{{ auth()->user()->unreadNotificationCount() }}"
>
    {{-- Sprite for the client-rendered dropdown rows (the centre page uses
         partials.notification-icon inline instead). --}}
    <svg class="hidden" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
        <symbol id="notif-cat-conversation" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
        </symbol>
        <symbol id="notif-cat-lead" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25H4.5a2.25 2.25 0 0 1-2.25-2.25V6.75m18 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m18 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/>
        </symbol>
        <symbol id="notif-cat-knowledge" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <path d="M6 2h8l4 4v14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1Z"/>
            <path d="M14 2v5h4"/>
        </symbol>
        <symbol id="notif-cat-widget" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
            <rect width="18" height="18" x="3" y="3" rx="2"/>
            <path d="M3 9h18"/>
        </symbol>
        <symbol id="notif-cat-team" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <path d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/>
        </symbol>
        <symbol id="notif-cat-billing" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
            <rect width="20" height="14" x="2" y="5" rx="2"/>
            <path d="M2 10h20"/>
        </symbol>
        <symbol id="notif-cat-security" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/>
        </symbol>
        <symbol id="notif-cat-system" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.02-.397-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z"/>
            <path d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
        </symbol>
    </svg>

    <button
        type="button"
        data-notifications-toggle
        class="btn btn-secondary btn-icon btn-sm relative"
        aria-haspopup="true"
        aria-expanded="false"
        aria-label="Notifications"
        title="Notifications — new alerts and activity"
    >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a24 24 0 0 0 3.844-.537M6.75 3.5A3.75 3.75 0 0 0 3 7.25v3.393c0 .463.168.913.47 1.27l1.09 1.286a.75.75 0 0 1 .13.406v.75A4.75 4.75 0 0 0 10.75 21h2.5a4.75 4.75 0 0 0 4.75-4.75v-.75a.75.75 0 0 1 .13-.406l1.09-1.286c.302-.357.47-.807.47-1.27V7.25A3.75 3.75 0 0 0 15.75 3.5h-9Z"/>
            <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 7.25a2.25 2.25 0 1 1 4.5 0"/>
        </svg>
        <span
            data-notifications-badge
            class="dm-notif-badge"
            hidden
        >0</span>
    </button>

    <div
        data-notifications-panel
        class="dm-notif-panel"
        role="dialog"
        aria-label="Notifications"
        hidden
    >
        <div class="dm-notif-head">
            <span class="text-sm font-bold text-slate-900 dark:text-white">Notifications</span>
            <button type="button" data-notifications-read-all class="dm-notif-action">
                Mark all read
            </button>
        </div>

        <div data-notifications-list class="dm-notif-list">
            <div data-notifications-state="loading" class="dm-notif-state">
                <span class="dm-notif-spinner" aria-hidden="true"></span>
                Loading notifications…
            </div>
            <div data-notifications-state="empty" class="dm-notif-state" hidden>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a24 24 0 0 0 3.844-.537M6.75 3.5A3.75 3.75 0 0 0 3 7.25v3.393c0 .463.168.913.47 1.27l1.09 1.286a.75.75 0 0 1 .13.406v.75A4.75 4.75 0 0 0 10.75 21h2.5a4.75 4.75 0 0 0 4.75-4.75v-.75a.75.75 0 0 1 .13-.406l1.09-1.286c.302-.357.47-.807.47-1.27V7.25A3.75 3.75 0 0 0 15.75 3.5h-9Z"/>
                </svg>
                You're all caught up.
            </div>
            <div data-notifications-state="error" class="dm-notif-state dm-notif-state--error" hidden>
                <span>Couldn't load notifications.</span>
                <button type="button" data-notifications-retry class="dm-notif-action">Retry</button>
            </div>
            <div data-notifications-state="offline" class="dm-notif-state" hidden>
                You're offline — new alerts will appear when the connection returns.
            </div>
        </div>

        <div class="dm-notif-foot">
            <a href="{{ route('notifications.index') }}">View all notifications</a>
            <a href="{{ route('settings.notifications') }}">Preferences</a>
        </div>
    </div>
</div>
