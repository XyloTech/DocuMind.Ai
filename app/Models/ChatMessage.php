<?php

namespace App\Models;

use App\Enums\ChatRole;
use App\Enums\MessageStatus;
use Database\Factories\ChatMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'chat_id',
    'role',
    'content',
    'credits_cost',
    'model_used',
    'prompt_tokens',
    'completion_tokens',
    'latency_ms',
    'status',
    'sources',
    'was_helpful',
])]
class ChatMessage extends Model
{
    /** @use HasFactory<ChatMessageFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => ChatRole::class,
            'status' => MessageStatus::class,
            'content' => 'string',
            'credits_cost' => 'integer',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'latency_ms' => 'integer',
            'sources' => 'array',
            'was_helpful' => 'boolean',
        ];
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    /**
     * Stored source badges: [{chunk_index, page_from, page_to, score, snippet}].
     *
     * Named to avoid shadowing the `sources` attribute.
     *
     * @return list<array{chunk_index: int, page_from: int, page_to: int, score: float, snippet: string}>
     */
    public function sourceBadges(): array
    {
        $sources = $this->sources;

        return is_array($sources) ? array_values($sources) : [];
    }

    /**
     * Source badges normalised for rendering, so a missing key can never blank
     * or throw on an otherwise valid answer.
     *
     * @return list<array{position: int, from: int, to: int, score: float, snippet: string, keyword: bool}>
     */
    public function citationRows(): array
    {
        $badges = $this->sourceBadges();

        return array_map(
            static function (array $source, int $index): array {
                $from = (int) ($source['page_from'] ?? 0);

                return [
                    'position' => $index + 1,
                    'from' => $from,
                    'to' => (int) ($source['page_to'] ?? $from),
                    'score' => (float) ($source['score'] ?? 0),
                    'snippet' => (string) ($source['snippet'] ?? ''),
                    'keyword' => array_key_exists('keyword_score', $source),
                ];
            },
            $badges,
            array_keys($badges),
        );
    }
}
