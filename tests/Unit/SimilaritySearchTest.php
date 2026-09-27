<?php

namespace Tests\Unit;

use App\Services\RAG\SimilaritySearch;
use PHPUnit\Framework\TestCase;

class SimilaritySearchTest extends TestCase
{
    public function test_identical_vectors_score_one(): void
    {
        $this->assertEqualsWithDelta(1.0, $this->score([0.6, 0.8], [0.6, 0.8]), 1e-9);
    }

    public function test_orthogonal_vectors_score_zero(): void
    {
        $this->assertSame(0.0, $this->score([1.0, 0.0], [0.0, 1.0]));
    }

    public function test_opposite_vectors_score_negative_one(): void
    {
        $this->assertSame(-1.0, $this->score([1.0, 0.0], [-1.0, 0.0]));
    }

    public function test_chunks_are_ranked_by_descending_score(): void
    {
        $ranked = (new SimilaritySearch)->rank([1.0, 0.0, 0.0], [
            $this->chunk(0, [0.0, 1.0, 0.0]),
            $this->chunk(1, [-1.0, 0.0, 0.0]),
            $this->chunk(2, [1.0, 0.0, 0.0]),
        ], 3);

        $this->assertSame([2, 0, 1], array_column(array_column($ranked, 'chunk'), 'chunk_index'));
        $this->assertSame([1.0, 0.0, -1.0], array_column($ranked, 'score'));
    }

    public function test_top_k_limits_the_result_set(): void
    {
        $ranked = (new SimilaritySearch)->rank([1.0, 0.0], [
            $this->chunk(0, [1.0, 0.0]),
            $this->chunk(1, [0.5, 0.5]),
            $this->chunk(2, [0.0, 1.0]),
        ], 2);

        $this->assertCount(2, $ranked);
        $this->assertSame([0, 1], array_column(array_column($ranked, 'chunk'), 'chunk_index'));
    }

    public function test_equal_scores_break_ties_on_the_lowest_chunk_index(): void
    {
        $ranked = (new SimilaritySearch)->rank([1.0, 0.0], [
            $this->chunk(9, [0.0, 1.0]),
            $this->chunk(4, [0.0, -1.0]),
            $this->chunk(7, [0.0, 0.0]),
        ], 3);

        $this->assertSame([4, 7, 9], array_column(array_column($ranked, 'chunk'), 'chunk_index'));
    }

    public function test_an_empty_candidate_set_returns_nothing(): void
    {
        $this->assertSame([], (new SimilaritySearch)->rank([1.0, 0.0], [], 4));
    }

    /**
     * @param  list<float>  $query
     * @param  list<float>  $vector
     */
    private function score(array $query, array $vector): float
    {
        $ranked = (new SimilaritySearch)->rank($query, [$this->chunk(0, $vector)], 1);

        return $ranked[0]['score'];
    }

    /**
     * @param  list<float>  $vector
     * @return array{chunk_index: int, text: string, page_from: int, page_to: int, vector: list<float>}
     */
    private function chunk(int $index, array $vector): array
    {
        return [
            'chunk_index' => $index,
            'text' => 'Chunk '.$index,
            'page_from' => 1,
            'page_to' => 1,
            'vector' => $vector,
        ];
    }
}
