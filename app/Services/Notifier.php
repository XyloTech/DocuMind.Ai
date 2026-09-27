<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Exceptions\ChatException;
use App\Exceptions\EmbeddingException;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\AccountNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Single entry point for raising in-app notifications.
 *
 * Responsibilities kept in one place so call sites stay declarative:
 *  - preference filtering (non-essential opt-out, per-category, email);
 *  - burst deduplication via dedupe keys (one row per key per user);
 *  - queued email for opted-in, email-capable types;
 *  - failures never break the action that raised the event: a broken
 *    notifications table, a mailer outage or a bad recipient is logged
 *    and swallowed instead of turning a sign-in or an upload into a 500.
 *
 * Typical use:
 *
 *     Notifier::make()
 *         ->type(NotificationType::KnowledgeProcessed)
 *         ->to($document->user)
 *         ->title('PDF ready')
 *         ->body("{$document->name} is indexed and answering questions.")
 *         ->link(route('dashboard'))
 *         ->workspace($document->workspace_id)
 *         ->send();
 */
final class Notifier
{
    private NotificationType $type;

    /** @var Collection<int, User> */
    private Collection $recipients;

    private string $title = '';

    private ?string $body = null;

    private ?string $link = null;

    private ?string $linkLabel = null;

    private ?int $workspaceId = null;

    /** @var array<string, mixed> */
    private array $meta = [];

    private ?string $groupKey = null;

    private ?string $dedupeKey = null;

    private function __construct()
    {
        $this->recipients = collect();
    }

    public static function make(): self
    {
        return new self;
    }

    /**
     * Outage watch: a burst of provider failures becomes a single platform
     * notice.
     *
     * Called from the global reportable hook so integration failures are
     * visible even when no call site raises its own notification. The counter
     * keeps one-off errors silent — the caller's own notification already
     * covers those — and turns a repeating pattern into exactly one notice
     * per failure class per ten minutes.
     */
    public static function integrationError(Throwable $exception): void
    {
        if (! $exception instanceof ChatException && ! $exception instanceof EmbeddingException) {
            return;
        }

        try {
            // Ten-minute buckets: 202609271434 → 20260927143.
            $bucket = substr(now()->format('YmdHi'), 0, -1);
            $key = 'integration-failures.'.class_basename($exception).'.'.$bucket;
            $hits = Cache::add($key, 1, now()->addMinutes(10)) ? 1 : (int) Cache::increment($key);

            if ($hits !== 3) {
                return;
            }

            self::make()
                ->type(NotificationType::IntegrationError)
                ->to(User::admins()->get())
                ->title(class_basename($exception).' errors repeating')
                ->body(Str::limit($exception->getMessage(), 300))
                ->link(route('dashboard'), 'Open dashboard')
                ->dedupe('system.integration.'.$key)
                ->send();
        } catch (Throwable $failure) {
            report($failure);
        }
    }

    public function type(NotificationType $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * @param  User|Collection<int, User>|array<int, User>|null  $users
     */
    public function to(User|Collection|array|null $users): self
    {
        $incoming = match (true) {
            $users instanceof User => [$users],
            $users === null => [],
            is_array($users) => $users,
            default => $users->all(),
        };

        $this->recipients = $this->recipients
            ->concat($incoming)
            ->filter(fn ($user): bool => $user instanceof User)
            ->unique('id')
            ->values();

        return $this;
    }

    /**
     * Convenience for tenant-scoped events: every member of a workspace,
     * optionally skipping the person who caused the event. A null workspace
     * (older sites pre-tenancy) simply has no members to notify.
     */
    public function toWorkspace(int|object|null $workspace, ?User $except = null): self
    {
        $workspaceId = is_object($workspace) ? (int) $workspace->id : $workspace;

        if ($workspaceId === null) {
            return $this->workspace(null);
        }

        $members = User::query()
            ->whereHas('workspaces', fn ($query) => $query->where('workspaces.id', $workspaceId))
            ->get();

        if ($except !== null) {
            $members = $members->reject(
                fn (User $member): bool => $member->getKey() === $except->getKey(),
            )->values();
        }

        return $this->to($members)->workspace($workspaceId);
    }

    public function title(string $title): self
    {
        $this->title = Str::limit($title, 160, '');

        return $this;
    }

    public function body(?string $body): self
    {
        $this->body = $body === null ? null : Str::limit($body, 500);

        return $this;
    }

    public function link(?string $link, ?string $label = null): self
    {
        $this->link = $link === null ? null : Str::limit($link, 500);
        $this->linkLabel = $label === null ? null : Str::limit($label, 60);

        return $this;
    }

    public function workspace(?int $workspaceId): self
    {
        $this->workspaceId = $workspaceId;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function meta(array $meta): self
    {
        $this->meta = $meta;

        return $this;
    }

    /**
     * Soft grouping hint the client uses to collapse related toasts.
     */
    public function group(?string $groupKey): self
    {
        $this->groupKey = $groupKey === null ? null : Str::limit($groupKey, 120);

        return $this;
    }

    /**
     * Hard dedupe: repeated sends with the same key update the existing row
     * instead of creating another (and never re-toast, since created_at is
     * preserved). NULL disables dedupe.
     */
    public function dedupe(?string $dedupeKey): self
    {
        $this->dedupeKey = $dedupeKey === null ? null : Str::limit($dedupeKey, 120);

        return $this;
    }

    /**
     * Persist for every allowed recipient. Returns the rows created (or
     * updated) so callers can assert in tests.
     *
     * @return Collection<int, UserNotification>
     */
    public function send(): Collection
    {
        if (! isset($this->type) || $this->title === '' || $this->recipients->isEmpty()) {
            return collect();
        }

        $rows = collect();

        foreach ($this->recipients as $recipient) {
            try {
                $row = $this->storeFor($recipient);

                if ($row !== null) {
                    $rows->push($row);
                }

                $this->mailTo($recipient);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $rows;
    }

    private function storeFor(User $recipient): ?UserNotification
    {
        if (! $this->allowsInApp($recipient)) {
            return null;
        }

        if ($this->dedupeKey !== null) {
            $existing = UserNotification::query()
                ->where('user_id', $recipient->getKey())
                ->where('dedupe_key', $this->dedupeKey)
                ->first();

            if ($existing !== null) {
                // Refresh content, counts and the type itself (an indexing row
                // graduates to "ready", a queued row to "failed") but keep
                // created_at (so the poller never re-toasts) and read_at (so a
                // read stays read).
                $existing->forceFill([
                    'type' => $this->type->value,
                    'category' => $this->type->category()->value,
                    'severity' => $this->type->severity()->value,
                    'title' => $this->title,
                    'body' => $this->body,
                    'link' => $this->link,
                    'link_label' => $this->linkLabel,
                    'meta' => $this->meta === [] ? null : $this->meta,
                ])->save();

                return $existing;
            }
        }

        return UserNotification::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $recipient->getKey(),
            'workspace_id' => $this->workspaceId,
            'type' => $this->type->value,
            'category' => $this->type->category()->value,
            'severity' => $this->type->severity()->value,
            'title' => $this->title,
            'body' => $this->body,
            'link' => $this->link,
            'link_label' => $this->linkLabel,
            'group_key' => $this->groupKey,
            'dedupe_key' => $this->dedupeKey,
            'meta' => $this->meta === [] ? null : $this->meta,
            'read_at' => null,
            'created_at' => now(),
        ]);
    }

    /**
     * Essential types always land in-app; everything else respects the
     * non-essential master switch and the per-category toggle.
     */
    private function allowsInApp(User $recipient): bool
    {
        if ($this->type->essential()) {
            return true;
        }

        $prefs = $recipient->notificationPreferences();

        if (! ($prefs['non_essential'] ?? true)) {
            return false;
        }

        return (bool) ($prefs['categories'][$this->type->category()->value]['in_app'] ?? true);
    }

    private function mailTo(User $recipient): void
    {
        if (! $this->type->emailCapable()) {
            return;
        }

        $prefs = $recipient->notificationPreferences();

        if (! ($prefs['email'] ?? false)) {
            return;
        }

        if (! ($prefs['categories'][$this->type->category()->value]['email'] ?? false)) {
            return;
        }

        try {
            $recipient->notify(new AccountNotification(
                type: $this->type,
                title: $this->title,
                body: $this->body,
                link: $this->link,
            ));
        } catch (Throwable) {
            // A mailer outage must never roll back the action that raised
            // the event; the in-app row is already stored.
        }
    }
}
