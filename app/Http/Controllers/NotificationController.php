<?php

namespace App\Http\Controllers;

use App\Enums\NotificationCategory;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The notification centre: server-rendered feed with filters for the full
 * page, plus small JSON endpoints the header bell polls. Every query is
 * pinned to the authenticated user — there is no path to another account's
 * rows.
 */
class NotificationController extends Controller
{
    /**
     * Full notification centre page (search, category and read-state
     * filters are ordinary GET params so it works without JS).
     */
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'category' => ['nullable', 'string', Rule::in(array_column(NotificationCategory::cases(), 'value'))],
            'status' => ['nullable', 'string', Rule::in(['all', 'unread', 'read'])],
            'q' => ['nullable', 'string', 'max:80'],
        ]);

        $query = $request->user()->notificationsInApp();

        if (! empty($validated['category'])) {
            $query->where('category', $validated['category']);
        }

        if (($validated['status'] ?? 'all') === 'unread') {
            $query->unread();
        } elseif (($validated['status'] ?? 'all') === 'read') {
            $query->whereNotNull('read_at');
        }

        if (! empty(trim($validated['q'] ?? ''))) {
            $needle = '%'.trim($validated['q']).'%';

            $query->where(function ($inner) use ($needle): void {
                $inner->where('title', 'like', $needle)
                    ->orWhere('body', 'like', $needle);
            });
        }

        return view('notifications.index', [
            'notifications' => $query->paginate(20)->withQueryString(),
            'filters' => [
                'category' => (string) ($validated['category'] ?? ''),
                'status' => (string) ($validated['status'] ?? 'all'),
                'q' => (string) trim($validated['q'] ?? ''),
            ],
            'categories' => NotificationCategory::cases(),
            'unreadCount' => $request->user()->unreadNotificationCount(),
        ]);
    }

    /**
     * Newest rows for the header dropdown.
     */
    public function feed(Request $request): JsonResponse
    {
        $items = $request->user()->notificationsInApp()
            ->limit(10)
            ->get()
            ->map(fn (UserNotification $notification): array => $notification->toFeedArray());

        return response()->json([
            'notifications' => $items,
            'unread' => $request->user()->unreadNotificationCount(),
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Rows created since `since` — what the poller turns into toasts.
     * `server_time` doubles as the next poll's cursor.
     */
    public function poll(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'since' => ['nullable', 'string', 'max:40'],
        ]);

        $since = null;

        if (! empty($validated['since'])) {
            try {
                $since = CarbonImmutable::parse($validated['since']);
            } catch (\Throwable) {
                $since = null;
            }
        }

        $query = $request->user()->notificationsInApp();

        if ($since !== null) {
            $query->where('created_at', '>', $since);
        }

        $items = $query
            ->reorder('created_at')
            ->limit(50)
            ->get()
            ->map(fn (UserNotification $notification): array => $notification->toFeedArray());

        return response()->json([
            'notifications' => $items,
            'unread' => $request->user()->unreadNotificationCount(),
            'server_time' => now()->toIso8601String(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread' => $request->user()->unreadNotificationCount(),
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Scope-checked by query, not by route binding, so another user's id is
     * a 404 rather than an authorization oracle.
     */
    public function markRead(Request $request, string $notification): JsonResponse
    {
        $row = $request->user()->notificationsInApp()
            ->whereKey($notification)
            ->firstOrFail();

        $row->markAsRead();

        return response()->json([
            'unread' => $request->user()->unreadNotificationCount(),
        ]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->notificationsInApp()
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json(['unread' => 0]);
    }

    /**
     * Preferences screen in Settings.
     */
    public function preferences(Request $request): View
    {
        return view('notifications.preferences', [
            'preferences' => $request->user()->notificationPreferences(),
            'categories' => NotificationCategory::cases(),
            'typesByEmail' => $this->emailTypesByCategory(),
        ]);
    }

    public function updatePreferences(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'non_essential' => ['sometimes', 'nullable', 'boolean'],
            'browser' => ['sometimes', 'nullable', 'boolean'],
            'email' => ['sometimes', 'nullable', 'boolean'],
            'categories' => ['sometimes', 'nullable', 'array'],
            'categories.*.in_app' => ['sometimes', 'nullable', 'boolean'],
            'categories.*.email' => ['sometimes', 'nullable', 'boolean'],
        ]);

        $categories = [];

        foreach (NotificationCategory::cases() as $category) {
            $categories[$category->value] = [
                'in_app' => (bool) ($validated['categories'][$category->value]['in_app'] ?? true),
                'email' => (bool) ($validated['categories'][$category->value]['email'] ?? false),
            ];
        }

        $request->user()->forceFill([
            'notification_preferences' => [
                'non_essential' => (bool) ($validated['non_essential'] ?? true),
                'browser' => (bool) ($validated['browser'] ?? false),
                'email' => (bool) ($validated['email'] ?? false),
                'categories' => $categories,
            ],
        ])->save();

        return back()->with('status', 'Notification preferences saved.');
    }

    /**
     * Which categories actually have email-capable types, so the UI can
     * grey out pointless toggles instead of silently doing nothing.
     *
     * @return array<string, int>
     */
    private function emailTypesByCategory(): array
    {
        $counts = [];

        foreach (\App\Enums\NotificationType::cases() as $type) {
            if ($type->emailCapable()) {
                $key = $type->category()->value;
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
