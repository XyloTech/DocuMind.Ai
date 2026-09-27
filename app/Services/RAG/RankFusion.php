<?php

namespace App\Services\RAG;

/**
 * Reciprocal Rank Fusion.
 *
 * Keyword and vector scores live on incompatible scales, so instead of
 * blending the numbers we blend the *positions*: every list contributes
 * 1 / (k + rank). A chunk that both retrievers like wins, and a chunk that only
 * one likes can still surface — which is exactly what a paraphrase-heavy
 * question needs.
 *
 * Keys are treated as opaque identifiers so a caller can fuse across several
 * documents at once (e.g. "12:4"), not just chunk indexes of one document.
 */
class RankFusion
{
    /**
     * @param  list<array{chunk_index: int|string}>  ...$rankedLists  each ordered best-first
     * @return list<array{chunk_index: int|string, score: float}>
     */
    public function fuse(array ...$rankedLists): array
    {
        $constant = max(1, (int) config('rag.rrf_k', 60));
        $scores = [];

        foreach ($rankedLists as $list) {
            foreach (array_values($list) as $position => $item) {
                $key = $item['chunk_index'];
                $scores[(string) $key] = ($scores[(string) $key] ?? 0.0) + 1 / ($constant + $position + 1);
            }
        }

        $fused = [];

        foreach ($scores as $key => $score) {
            $fused[] = [
                'chunk_index' => is_numeric($key) && str_contains($key, '.') === false && (string) (int) $key === $key
                    ? (int) $key
                    : $key,
                'score' => $score,
            ];
        }

        usort($fused, fn (array $a, array $b): int => $b['score'] <=> $a['score']
            ?: (string) $a['chunk_index'] <=> (string) $b['chunk_index']);

        return $fused;
    }
}
