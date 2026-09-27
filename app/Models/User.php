<?php

namespace App\Models;

use App\Enums\NotificationCategory;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'firebase_uid', 'avatar_url'])]
#[Hidden([
    'password',
    'remember_token',
    'store_chat_history',
    'allow_model_training',
    'privacy_consent_at',
    'privacy_consent_version',
    'chat_retention_days',
    'notification_preferences',
])]

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'credits' => 'integer',
            'role' => UserRole::class,
            'is_banned' => 'boolean',
            'last_login_at' => 'datetime',
            'store_chat_history' => 'boolean',
            'allow_model_training' => 'boolean',
            'privacy_consent_at' => 'datetime',
            'chat_retention_days' => 'integer',
            'notification_preferences' => 'array',
        ];
    }

    /**
     * False until the owner has answered the consent prompt, which is what
     * gates every retention decision on the account.
     */
    public function hasGivenConsent(): bool
    {
        return $this->privacy_consent_at !== null;
    }

    /**
     * Conversations are only written to the database while this is true.
     */
    public function allowsHistoryStorage(): bool
    {
        return (bool) $this->store_chat_history;
    }

    /**
     * Anonymised conversations may only be exported for training while true
     * and only while consent is still on record.
     */
    public function allowsTraining(): bool
    {
        return $this->hasGivenConsent() && (bool) $this->allow_model_training;
    }

    /**
     * Privacy decisions recorded for this account, newest first.
     *
     * @return HasMany<PrivacyAuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(PrivacyAuditLog::class)->orderByDesc('created_at');
    }

    /**
     * In-app notifications for this account, newest first.
     *
     * @return HasMany<UserNotification, $this>
     */
    public function notificationsInApp(): HasMany
    {
        return $this->hasMany(UserNotification::class)->orderByDesc('created_at');
    }

    public function unreadNotificationCount(): int
    {
        return $this->notificationsInApp()->unread()->count();
    }

    /**
     * Stored preferences merged over the defaults, so a NULL column (every
     * pre-existing account) behaves exactly like an explicit opt-in.
     *
     * @return array{non_essential: bool, browser: bool, email: bool, categories: array<string, array{in_app: bool, email: bool}>}
     */
    public function notificationPreferences(): array
    {
        $stored = is_array($this->notification_preferences) ? $this->notification_preferences : [];

        $categories = [];

        foreach (NotificationCategory::cases() as $category) {
            $categories[$category->value] = [
                'in_app' => (bool) ($stored['categories'][$category->value]['in_app'] ?? true),
                'email' => (bool) ($stored['categories'][$category->value]['email'] ?? false),
            ];
        }

        return [
            'non_essential' => (bool) ($stored['non_essential'] ?? true),
            'browser' => (bool) ($stored['browser'] ?? false),
            'email' => (bool) ($stored['email'] ?? false),
            'categories' => $categories,
        ];
    }

    /**
     * @return HasMany<Workspace, $this>
     */
    public function ownedWorkspaces(): HasMany
    {
        return $this->hasMany(Workspace::class, 'owner_id');
    }

    /**
     * @return BelongsToMany<Workspace, $this>
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class)->withPivot('role')->withTimestamps();
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * @return HasMany<Chat, $this>
     */
    public function chats(): HasMany
    {
        return $this->hasMany(Chat::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function hasAdminAccess(): bool
    {
        return in_array($this->role, [UserRole::Admin, UserRole::Support, UserRole::Analyst], true);
    }

    public function canManagePlatform(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * Platform administrators: the audience for maintenance, embed-health and
     * outage notices that do not belong to any one workspace.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeAdmins(Builder $query): Builder
    {
        return $query->where('role', UserRole::Admin);
    }

    public function isBanned(): bool
    {
        return (bool) $this->is_banned;
    }

    /**
     * Credits available for this account, never allowed to go negative.
     */
    public function hasCredits(int $amount = 1): bool
    {
        return $this->credits >= $amount;
    }

    /**
     * Add credits to the account using an atomic increment.
     */
    public function grantCredits(int $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        $this->increment('credits', $amount);
    }
}
