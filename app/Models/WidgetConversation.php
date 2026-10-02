<?php

namespace App\Models;

use Database\Factories\WidgetConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Contracts\Encryption\DecryptException;

#[Fillable([
    'site_id',
    'visitor_id',
    'visitor_id_hash',
    'visitor_email',
    'visitor_email_hash',
    'visitor_email_consent_at',
    'message_count',
    'workspace_id',
    'status',
    'classification',
    'assigned_user_id',
    'escalated_at',
    'escalation_reason',
    'resolved_at',
    'follow_up_status',
])]
#[Hidden(['visitor_email', 'visitor_id'])]
class WidgetConversation extends Model
{
    /** @use HasFactory<WidgetConversationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'message_count' => 'integer',
            'visitor_id' => 'encrypted',
            'visitor_email' => 'encrypted',
            'visitor_email_consent_at' => 'datetime',
            'escalated_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * @return HasMany<WidgetMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(WidgetMessage::class);
    }

    public function safeVisitorEmail(): ?string
    {
        try {
            return $this->visitor_email;
        } catch (DecryptException) {
            return null;
        }
    }

    public function safeVisitorId(): ?string
    {
        try {
            return $this->visitor_id;
        } catch (DecryptException) {
            return null;
        }
    }

    protected static function booted(): void
    {
        static::saving(function (self $conversation): void {
            $visitorId = $conversation->safeVisitorId();
            if ($visitorId !== null) {
                $conversation->visitor_id_hash = hash_hmac('sha256', $visitorId, (string) config('app.key'));
            }

            $visitorEmail = $conversation->safeVisitorEmail();
            if ($visitorEmail !== null) {
                $conversation->visitor_email_hash = hash_hmac(
                    'sha256',
                    mb_strtolower(trim($visitorEmail)),
                    (string) config('app.key'),
                );
            }
        });
    }
}
