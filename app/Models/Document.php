<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'workspace_id',
    'filename',
    'file_path',
    'mime_type',
    'size_bytes',
    'status',
    'progress',
    'page_count',
    'chunk_count',
    'error_message',
    'suggested_questions',
    'doc_hash',
    'processed_at',
])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'progress' => 'integer',
            'page_count' => 'integer',
            'chunk_count' => 'integer',
            'size_bytes' => 'integer',
            'error_message' => 'string',
            'suggested_questions' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return HasMany<DocumentChunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }

    public function isProcessed(): bool
    {
        return $this->status === DocumentStatus::Processed;
    }

    /**
     * Starter questions mined from the document during ingestion.
     *
     * @return list<string>
     */
    public function suggestedQuestions(): array
    {
        $questions = $this->suggested_questions;

        if (! is_array($questions)) {
            return [];
        }

        return array_values(array_map('strval', array_filter($questions, 'is_string')));
    }

    /**
     * Human readable file size, e.g. "1.4 MB".
     */
    public function humanSize(): string
    {
        $bytes = $this->size_bytes;

        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return round($bytes, $unit === 'B' ? 0 : 1).' '.$unit;
            }

            $bytes /= 1024;
        }

        return (string) $bytes;
    }
}
