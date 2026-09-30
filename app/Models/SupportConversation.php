<?php

namespace App\Models;

use App\Enums\SupportStatus;
use Database\Factories\SupportConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'agent_id',
    'chat_id',
    'widget_conversation_id',
    'status',
    'message_count',
    'last_message_at',
    'resolved_at',
])]
class SupportConversation extends Model
{
    /** @use HasFactory<SupportConversationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SupportStatus::class,
            'message_count' => 'integer',
            'last_message_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * The dashboard chat the handoff was raised from, if any.
     */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    /**
     * The widget conversation this ticket was opened from. Anonymous widget
     * visitors have no user account, so this is the only origin they have.
     */
    public function widgetConversation(): BelongsTo
    {
        return $this->belongsTo(WidgetConversation::class);
    }

    /**
     * @return HasMany<SupportMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class);
    }

    /**
     * Staff-only guard used by the admin inbox: customers never see the
     * admin transcript routes, and support agents never see each other's
     * internal notes without the gate already allowing it.
     */
    public function isLive(): bool
    {
        return $this->status->isLive();
    }

    /**
     * Who opened this ticket, for staff-facing surfaces. Widget visitors
     * have no account, so they are named after the assistant they wrote in.
     */
    public function originLabel(): string
    {
        if ($this->widgetConversation !== null) {
            $site = $this->widgetConversation->site;

            return 'Widget visitor'.($site !== null ? ' · '.$site->name : '');
        }

        return $this->user?->name ?? 'Deleted account';
    }
}
