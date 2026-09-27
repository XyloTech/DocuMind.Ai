<?php

namespace App\Services\RAG;

use App\Models\DocumentChunk;

/**
 * Lexical half of hybrid retrieval.
 *
 * Vector search is strong on paraphrase and weak on rare literal terms
 * (product codes, names, "Firestore"). This scores the same chunks by term
 * overlap so exact words still surface, and the two rankings are fused
 * downstream.
 */
class KeywordSearch
{
    /**
     * Rank a document's chunks by weighted term overlap with the query.
     *
     * Callers that already hold the document's chunks (both retrieval paths do,
     * because the vector store has them cached) pass them in and skip a second
     * trip to the `document_chunks` table.
     *
     * @param  list<array{chunk_index?: int|string, text?: string, chunk_text?: string}>|null  $chunks
     * @return list<array{chunk_index: int, score: float}>
     */
    public function search(int $documentId, string $query, int $limit = 25, ?array $chunks = null): array
    {
        $terms = $this->terms($query);
        if ($terms === []) {
            return [];
        }

        $chunks = $chunks ?? $this->load($documentId);

        $tokenised = [];
        $lengths = [];
        $total = 0;

        foreach ($chunks as $chunk) {
            $index = (int) ($chunk['chunk_index'] ?? 0);
            $words = $this->tokenize((string) ($chunk['text'] ?? $chunk['chunk_text'] ?? ''));
            $tokenised[$index] = $words;
            $lengths[$index] = max(1, count($words));
            $total += $lengths[$index];
        }

        if ($total === 0) {
            return [];
        }

        $average = $total / max(1, count($lengths));
        $idf = [];

        foreach ($terms as $term) {
            $documents = 0;

            foreach ($tokenised as $words) {
                if (in_array($term, $words, true)) {
                    $documents++;
                }
            }

            // A term in every chunk cannot separate them; BM25's own idf
            // collapses to ~0 there, so use it as the stop-word floor.
            if ($documents === 0) {
                continue;
            }

            $weight = log(1 + (count($tokenised) - $documents + 0.5) / ($documents + 0.5));

            if ($weight < 0.2) {
                continue;
            }

            $idf[$term] = $weight;
        }

        if ($idf === []) {
            return [];
        }

        $scored = [];

        foreach ($tokenised as $index => $words) {
            $score = 0.0;
            $counts = array_count_values($words);

            foreach ($idf as $term => $weight) {
                if (isset($counts[$term])) {
                    $score += $weight * (1 + log($counts[$term]));
                }
            }

            if ($score <= 0.0) {
                continue;
            }

            // BM25 length normalisation keeps long chunks from dominating.
            $normalised = $score * ($average / $lengths[$index]);

            $scored[] = ['chunk_index' => (int) $index, 'score' => $normalised];
        }

        usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']
            ?: $a['chunk_index'] <=> $b['chunk_index']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * Cut a window around the first matched term instead of always taking the
     * start of the chunk. A 400-character head slice routinely cuts off the
     * very word that made the chunk relevant.
     *
     * @param  list<string>  $terms
     */
    public function focus(string $text, array $terms, int $chars): string
    {
        if ($terms === [] || mb_strlen($text) <= $chars) {
            return mb_substr($text, 0, $chars);
        }

        $lower = mb_strtolower($text);
        $best = null;

        foreach ($terms as $term) {
            $position = mb_stripos($lower, $term);

            if ($position !== false) {
                $best = $position;
                break;
            }
        }

        if ($best === null) {
            return mb_substr($text, 0, $chars);
        }

        $start = max(0, $best - (int) ($chars * 0.35));

        // Prefer starting at a sentence boundary so the model never sees a
        // fragment opening mid-sentence.
        $boundary = mb_strpos(mb_substr($text, $start, 120), '. ');

        if ($boundary !== false && $start > 0) {
            $start += $boundary + 1;
        }

        $window = trim(mb_substr($text, $start, $chars));

        return $start > 0 ? '… '.$window : $window;
    }

    /**
     * Distinct, meaningful query terms.
     *
     * @return list<string>
     */
    public function terms(string $query): array
    {
        $tokens = $this->tokenize($query);

        $unique = array_values(array_unique($tokens));
        $filtered = array_values(array_filter(
            $unique,
            fn (string $token): bool => mb_strlen($token) > 2 || in_array($token, ['pi', 'ui'], true),
        ));

        return $filtered;
    }

    /**
     * @return list<array{chunk_index: int, text: string}>
     */
    private function load(int $documentId): array
    {
        $chunks = DocumentChunk::query()
            ->where('document_id', $documentId)
            ->get(['chunk_index', 'chunk_text']);

        return $chunks->map(fn (DocumentChunk $chunk): array => [
            'chunk_index' => (int) $chunk->chunk_index,
            'text' => (string) $chunk->chunk_text,
        ])->all();
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        $lower = mb_strtolower($text);

        return preg_split('/[^\p{L}\p{N}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
