<?php

namespace App\Models;

use App\Enums\ChatRole;
use App\Enums\MessageStatus;
use Database\Factories\WidgetMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'site_id',
    'widget_conversation_id',
    'role',
    'status',
    'error_reason',
    'content',
    'sources',
    'model_used',
    'latency_ms',
    'credit_cost',
    'was_refused',
    'was_fallback',
    'was_helpful',
])]
class WidgetMessage extends Model
{
    /** @use HasFactory<WidgetMessageFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => ChatRole::class,
            'status' => MessageStatus::class,
            'content' => 'encrypted',
            'sources' => 'array',
            'model_used' => 'string',
            'latency_ms' => 'integer',
            'credit_cost' => 'integer',
            'was_refused' => 'boolean',
            'was_fallback' => 'boolean',
            'was_helpful' => 'boolean',
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

    /**
     * @return list<array<string, mixed>>
     */
    public function sourceBadges(): array
    {
        $sources = $this->sources;

        return is_array($sources) ? array_values($sources) : [];
    }
}
