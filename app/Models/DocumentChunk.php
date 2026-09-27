<?php

namespace App\Models;

use Database\Factories\DocumentChunkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'document_id',
    'chunk_index',
    'chunk_text',
    'embedding',
    'embedding_norm',
    'page_from',
    'page_to',
    'char_start',
    'char_end',
    'token_estimate',
    'content_hash',
])]
class DocumentChunk extends Model
{
    /** @use HasFactory<DocumentChunkFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'embedding' => 'array',
            'embedding_norm' => 'float',
            'chunk_index' => 'integer',
            'page_from' => 'integer',
            'page_to' => 'integer',
            'char_start' => 'integer',
            'char_end' => 'integer',
            'token_estimate' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
