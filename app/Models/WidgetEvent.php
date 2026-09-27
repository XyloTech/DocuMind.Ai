<?php

namespace App\Models;

use Database\Factories\WidgetEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'site_id',
    'workspace_id',
    'widget_conversation_id',
    'type',
    'visitor_id_hash',
    'meta',
])]
class WidgetEvent extends Model
{
    /** @use HasFactory<WidgetEventFactory> */
    use HasFactory;

    /** Engagement events the widget is allowed to report. Anything else is rejected. */
    public const TYPES = [
        'widget_loaded',
        'launcher_open',
        'launcher_close',
        'message_sent',
        'suggestion_click',
        'email_gate_shown',
    ];

    /**
     * Engagement rows are append-only; nothing updates them.
     */
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WidgetConversation::class, 'widget_conversation_id');
    }
}
