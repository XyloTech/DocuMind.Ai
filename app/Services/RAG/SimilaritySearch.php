<?php

namespace App\Services\RAG;

/**
 * Ranks chunks by cosine similarity against an L2-normalized query vector.
 *
 * Because stored vectors are normalized at ingestion, cosine similarity is a
 * pure dot product — no division, no sqrt in the hot loop.
 */
class SimilaritySearch
{
    /**
     * @param  list<float>  $query
     * @param  list<array{chunk_index: int, text: string, page_from: int, page_to: int, vector: list<float>}>  $chunks
     * @return list<array{chunk: array{chunk_index: int, text: string, page_from: int, page_to: int, vector: list<float>}, score: float}>
     */
    public function rank(array $query, array $chunks, int $topK = 4): array
    {
        if ($chunks === [] || $topK < 1) {
            return [];
        }

        $scored = [];

        foreach ($chunks as $chunk) {
            $scored[] = [
                'chunk' => $chunk,
                'score' => $this->dot($query, $chunk['vector'] ?? []),
            ];
        }

        usort($scored, function (array $a, array $b): int {
            return ($b['score'] <=> $a['score'])
                ?: ($a['chunk']['chunk_index'] <=> $b['chunk']['chunk_index']);
        });

        return array_slice($scored, 0, $topK);
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function dot(array $a, array $b): float
    {
        $length = min(count($a), count($b));
        $sum = 0.0;

        for ($i = 0; $i < $length; $i++) {
            $sum += $a[$i] * $b[$i];
        }

        return $sum;
    }
}
