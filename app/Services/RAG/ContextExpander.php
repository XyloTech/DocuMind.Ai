<?php

namespace App\Services\RAG;

/**
 * Widens a cited snippet with the sentence that came before it.
 *
 * Chunks are cut on sentence boundaries, so a hit often starts mid-thought
 * ("It is reviewed every quarter."). Appending the tail of the previous chunk
 * gives the model the subject of that sentence without diluting the citation —
 * the neighbour is clearly marked as context, never as a source.
 */
class ContextExpander
{
    /**
     * @param  array{chunk_index: int, text: string}  $hit
     * @param  list<array{chunk_index: int, text: string}>  $chunks  the whole document
     */
    public function expand(array $hit, array $chunks, int $maxChars = 0): string
    {
        $maxChars = $maxChars > 0 ? $maxChars : (int) config('rag.context_neighbour_chars', 220);

        $previous = $this->neighbour($hit['chunk_index'], $chunks, -1);

        if ($previous === null) {
            return $hit['text'];
        }

        $prefix = $this->tail($previous, $maxChars);

        if ($prefix === '') {
            return $hit['text'];
        }

        return $prefix.' '.$hit['text'];
    }

    /**
     * @param  list<array{chunk_index: int, text: string}>  $chunks
     */
    private function neighbour(int $index, array $chunks, int $offset): ?string
    {
        $target = $index + $offset;

        foreach ($chunks as $chunk) {
            if ($chunk['chunk_index'] === $target) {
                return trim((string) $chunk['text']);
            }
        }

        return null;
    }

    /**
     * Last whole sentences of the neighbour that fit the budget.
     */
    private function tail(string $text, int $maxChars): string
    {
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        $window = mb_substr($text, -$maxChars);

        // The window usually starts mid-sentence; drop that fragment and keep
        // from the first sentence boundary onwards.
        $start = mb_strpos($window, '. ');

        if ($start !== false) {
            $window = mb_substr($window, $start + 1);
        } elseif (mb_substr($window, -1) === '.') {
            return $window;
        }

        $window = trim($window);

        if ($window !== '' && ! str_ends_with($window, '.')) {
            return '';
        }

        return $window;
    }
}
