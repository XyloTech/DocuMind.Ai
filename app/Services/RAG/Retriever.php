<?php

namespace App\Services\RAG;

use App\Models\Document;

/**
 * Question → ranked, above-floor context snippets for a single document.
 *
 * Retrieval is hybrid: a vector ranking (paraphrase) and a BM25-style keyword
 * ranking (literal terms) are fused by position, and each surviving hit is
 * widened with its preceding chunk so sentences are not quoted out of context.
 */
class Retriever
{
    public function __construct(
        private readonly EmbeddingClient $embeddings,
        private readonly VectorStore $store,
        private readonly SimilaritySearch $search,
        private readonly KeywordSearch $keywords,
        private readonly RankFusion $fusion,
        private readonly ContextExpander $expander,
    ) {}

    /**
     * @return list<array{chunk_index: int, page_from: int, page_to: int, score: float, snippet: string}>
     */
    public function retrieve(Document $document, string $question): array
    {
        if (! $document->isProcessed()) {
            return [];
        }

        $chunks = $this->store->loadForDocument($document->getKey());

        if ($chunks === []) {
            return [];
        }

        $topK = (int) config('rag.top_k', 4);
        $floor = (float) config('rag.min_similarity', 0.15);
        $snippetChars = (int) config('rag.snippet_chars', 400);
        $poolSize = max($topK, (int) config('rag.retrieval_pool', 20));

        $query = $this->embeddings->embedOne($question);

        $vectorHits = $this->search->rank($query, $chunks, $poolSize);
        $vectorScores = [];

        foreach ($vectorHits as $hit) {
            $vectorScores[$hit['chunk']['chunk_index']] = $hit['score'];
        }

        $keywordHits = (bool) config('rag.hybrid', true)
            ? $this->keywords->search($document->getKey(), $question, $poolSize, $chunks)
            : [];

        $keywordScores = [];

        foreach ($keywordHits as $hit) {
            $keywordScores[$hit['chunk_index']] = $hit['score'];
        }

        $bestKeyword = $keywordScores === [] ? 0.0 : max($keywordScores);
        $keywordCutoff = $bestKeyword * (float) config('rag.keyword_confidence', 0.5);

        $fused = $this->fusion->fuse(
            array_map(
                fn (array $hit): array => ['chunk_index' => (int) $hit['chunk']['chunk_index']],
                $vectorHits,
            ),
            $keywordHits,
        );

        $byIndex = [];

        foreach ($chunks as $chunk) {
            $byIndex[$chunk['chunk_index']] = $chunk;
        }

        $hits = [];

        foreach (array_slice($fused, 0, $topK) as $candidate) {
            $index = $candidate['chunk_index'];
            $chunk = $byIndex[$index] ?? null;

            if ($chunk === null) {
                continue;
            }

            $score = $vectorScores[$index] ?? 0.0;
            $keywordScore = $keywordScores[$index] ?? 0.0;
            $semantic = $score >= $floor;
            $lexical = $bestKeyword > 0.0 && $keywordScore >= $keywordCutoff;

            // Either signal can carry a hit on its own: vectors understand
            // paraphrase, keywords rescue rare literal terms that do not
            // resemble the question at all.
            if (! $semantic && ! $lexical) {
                continue;
            }

            $text = $this->expander->expand(
                ['chunk_index' => $index, 'text' => (string) $chunk['text']],
                $chunks,
            );

            $snippet = mb_strlen($text) > $snippetChars && $keywordScore > 0.0
                ? $this->keywords->focus($text, $this->keywords->terms($question), $snippetChars)
                : mb_substr($text, 0, $snippetChars);

            $hit = [
                'chunk_index' => (int) $index,
                'page_from' => (int) $chunk['page_from'],
                'page_to' => (int) $chunk['page_to'],
                'score' => round($score, 4),
                'snippet' => trim($snippet),
            ];

            if (! $semantic) {
                $hit['keyword_score'] = round($keywordScore, 4);
            }

            $hits[] = $hit;
        }

        return $hits;
    }
}
