<?php

namespace App\Services\Ai;

/**
 * Deterministic, offline embedding driver.
 *
 * Used in tests and as a demo fallback when no API key is configured. It maps
 * word hashes into a dense vector, so texts that share vocabulary end up with a
 * positive cosine similarity — enough to exercise ranking, chunking and the
 * retrieval UI, but not a substitute for a real embedding model.
 */
class FakeEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(private readonly int $dimensions = 1536) {}

    public function label(): string
    {
        return 'fake';
    }

    public function embed(array $texts): array
    {
        return array_map(fn (string $text): array => $this->vector($text), $texts);
    }

    /**
     * @return list<float>
     */
    private function vector(string $text): array
    {
        $vector = array_fill(0, max(1, $this->dimensions), 0.0);

        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($tokens as $token) {
            $hash = crc32($token);
            $index = $hash % $this->dimensions;
            $sign = (($hash >> 15) & 1) === 1 ? 1.0 : -1.0;

            $vector[$index] += $sign;
        }

        if (! array_sum(array_map('abs', $vector))) {
            $vector[0] = 1.0;
        }

        return $vector;
    }
}
