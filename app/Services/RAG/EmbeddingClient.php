<?php

namespace App\Services\RAG;

use App\Exceptions\EmbeddingException;
use App\Services\Ai\EmbeddingProvider;
use Illuminate\Support\Facades\Cache;

class EmbeddingClient
{
    private const int MAX_INPUT_CHARS = 32_000;

    public function __construct(private readonly EmbeddingProvider $provider) {}

    /**
     * Embed texts in provider-sized batches, L2-normalizing every vector so
     * similarity search degenerates to a plain dot product.
     *
     * @param  list<string>  $texts
     * @param  (callable(int $done, int $total): void)|null  $onBatch
     * @return list<list<float>>
     *
     * @throws EmbeddingException
     */
    public function embed(array $texts, ?callable $onBatch = null): array
    {
        if ($texts === []) {
            return [];
        }

        $texts = array_values(array_map(fn (string $text): string => $this->truncate($text), $texts));
        $batches = array_chunk($texts, (int) config('rag.embedding_batch_size', 64));

        $vectors = [];
        $done = 0;

        foreach ($batches as $batch) {
            $embedded = $this->provider->embed($batch);

            if (count($embedded) !== count($batch)) {
                throw EmbeddingException::mismatchedResponse(count($batch), count($embedded));
            }

            foreach ($embedded as $vector) {
                $vectors[] = $this->normalize($vector);
            }

            $done += count($batch);

            if ($onBatch !== null) {
                $onBatch($done, count($texts));
            }
        }

        return $vectors;
    }

    /**
     * Embed a single query, reusing the previous vector for the same words.
     *
     * A query embedding depends only on the model and the text, so two visitors
     * asking the same question must not pay for two round trips: this is the
     * one call that sits on the critical path of every answer.
     *
     * @param  (callable(int $done, int $total): void)|null  $onBatch
     *
     * @throws EmbeddingException
     */
    public function embedOne(string $text, ?callable $onBatch = null): array
    {
        if ($onBatch !== null) {
            return $this->embed([$text], $onBatch)[0];
        }

        $ttl = max(0, (int) config('rag.query_embedding_ttl', 600));

        return Cache::remember(
            $this->queryCacheKey($text),
            now()->addSeconds($ttl),
            fn (): array => $this->embed([$text])[0],
        );
    }

    /**
     * The provider and model are part of the key so a model switch never serves
     * vectors from the previous one.
     */
    private function queryCacheKey(string $text): string
    {
        $signature = implode('|', [
            $this->provider->label(),
            (string) config('openai.embedding_model'),
            (string) config('rag.embedding_dimensions'),
        ]);

        return 'documind:query-embedding:'.hash('sha256', $signature."\0".$text);
    }

    /**
     * @param  list<float>  $vector
     * @return list<float>
     */
    private function normalize(array $vector): array
    {
        $sum = 0.0;

        foreach ($vector as $value) {
            $sum += $value * $value;
        }

        if ($sum <= 0.0) {
            return array_map(fn (): float => 0.0, $vector);
        }

        $norm = sqrt($sum);

        return array_map(fn (float $value): float => round($value / $norm, 6), $vector);
    }

    private function truncate(string $text): string
    {
        return mb_strlen($text) > self::MAX_INPUT_CHARS
            ? mb_substr($text, 0, self::MAX_INPUT_CHARS)
            : $text;
    }
}
