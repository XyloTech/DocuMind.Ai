<?php

namespace App\Models;

use App\Enums\NotificationCategory;
use App\Enums\NotificationSeverity;
use App\Enums\NotificationType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One in-app notification row. Rows are created directly (not through the
 * broadcast channels) because the centre only ever reads from the database —
 * toasts are delivered by polling this table, and the mail channel is a
 * separate opt-in handled by the emitter.
 */
#[Fillable([
    'user_id',
    'workspace_id',
    'type',
    'category',
    'severity',
    'title',
    'body',
    'link',
    'link_label',
    'group_key',
    'dedupe_key',
    'meta',
    'read_at',
    'created_at',
])]
class UserNotification extends Model
{
    use HasUuids;

    /** Only creation time is meaningful; rows are otherwise immutable. */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'category' => NotificationCategory::class,
            'severity' => NotificationSeverity::class,
            'meta' => 'array',
            'read_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function markAsRead(): void
    {
        if ($this->read_at === null) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }

    /**
     * Payload for the dropdown, poller and centre — deliberately free of
     * meta fields that could carry personal data beyond the title/body the
     * emitter already vetted.
     *
     * @return array<string, mixed>
     */
    public function toFeedArray(): array
    {
        return [
            'id' => $this->getKey(),
            'type' => $this->type->value,
            'category' => $this->category->value,
            'severity' => $this->severity->value,
            'title' => $this->title,
            'body' => $this->body,
            'link' => $this->link,
            'link_label' => $this->link_label,
            'read' => $this->read_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
