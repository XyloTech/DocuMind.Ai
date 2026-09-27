<?php

namespace App\Models;

use Database\Factories\WidgetConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    protected static function booted(): void
    {
        static::saving(function (self $conversation): void {
            if ($conversation->visitor_id !== null) {
                $conversation->visitor_id_hash = hash_hmac('sha256', $conversation->visitor_id, (string) config('app.key'));
            }

            if ($conversation->visitor_email !== null) {
                $conversation->visitor_email_hash = hash_hmac(
                    'sha256',
                    mb_strtolower(trim($conversation->visitor_email)),
                    (string) config('app.key'),
                );
            }
        });
    }
}
