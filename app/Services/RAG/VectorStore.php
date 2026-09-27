<?php

namespace App\Services\RAG;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Persists and loads chunk vectors.
 *
 * Vectors are JSON-encoded floats rounded to six decimals, keeping the format
 * portable. The encoder/decoder lives here so it can be swapped for a packed
 * float32 blob without touching callers.
 */
class VectorStore
{
    private const int CACHE_MINUTES = 5;

    /**
     * @param  list<array{chunk_index: int, text: string, page_from: int, page_to: int, char_start: int, char_end: int, token_estimate: int, content_hash: string, vector: list<float>}>  $rows
     */
    public function persist(Document $document, array $rows): void
    {
        DB::transaction(function () use ($document, $rows): void {
            DocumentChunk::query()->where('document_id', $document->getKey())->delete();

            if ($rows === []) {
                return;
            }

            $now = now();
            $payload = [];

            foreach ($rows as $row) {
                $payload[] = [
                    'document_id' => $document->getKey(),
                    'chunk_index' => $row['chunk_index'],
                    'chunk_text' => $row['text'],
                    'embedding' => json_encode($row['vector']),
                    'embedding_norm' => 1.0,
                    'page_from' => $row['page_from'],
                    'page_to' => $row['page_to'],
                    'char_start' => $row['char_start'],
                    'char_end' => $row['char_end'],
                    'token_estimate' => $row['token_estimate'],
                    'content_hash' => $row['content_hash'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DocumentChunk::query()->insert($payload);
        });

        $this->forget($document);
    }

    /**
     * @return list<array{chunk_index: int, text: string, page_from: int, page_to: int, vector: list<float>}>
     */
    public function loadForDocument(int $documentId): array
    {
        return Cache::remember(
            $this->cacheKey($documentId),
            now()->addMinutes(self::CACHE_MINUTES),
            function () use ($documentId): array {
                $chunks = DocumentChunk::query()
                    ->where('document_id', $documentId)
                    ->orderBy('chunk_index')
                    ->get(['chunk_index', 'chunk_text', 'page_from', 'page_to', 'embedding']);

                return $chunks->map(fn (DocumentChunk $chunk): array => [
                    'chunk_index' => (int) $chunk->chunk_index,
                    'text' => (string) $chunk->chunk_text,
                    'page_from' => (int) $chunk->page_from,
                    'page_to' => (int) $chunk->page_to,
                    'vector' => $this->decode($chunk->embedding),
                ])->all();
            },
        );
    }

    public function forget(Document $document): void
    {
        Cache::forget($this->cacheKey($document->getKey()));
    }

    /**
     * @return list<float>
     */
    private function decode(mixed $encoded): array
    {
        if (is_array($encoded)) {
            return array_map('floatval', $encoded);
        }

        if (! is_string($encoded) || $encoded === '') {
            return [];
        }

        $decoded = json_decode($encoded, true);

        return is_array($decoded) ? array_map('floatval', $decoded) : [];
    }

    private function cacheKey(int $documentId): string
    {
        return "documind:vectors:{$documentId}";
    }
}
