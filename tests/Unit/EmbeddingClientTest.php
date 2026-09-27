<?php

namespace Tests\Unit;

use App\Services\Ai\EmbeddingProvider;
use App\Services\RAG\EmbeddingClient;
use Tests\TestCase;

class EmbeddingClientTest extends TestCase
{
    public function test_vectors_are_l2_normalized_and_rounded(): void
    {
        $vectors = $this->client(new StaticProvider([3.0, 4.0]))->embed(['anything']);

        $this->assertEqualsWithDelta(0.6, $vectors[0][0], 1e-6);
        $this->assertEqualsWithDelta(0.8, $vectors[0][1], 1e-6);
        $this->assertEqualsWithDelta(1.0, $this->norm($vectors[0]), 1e-5);
    }

    public function test_inputs_are_sent_in_batches_of_the_configured_size(): void
    {
        config(['rag.embedding_batch_size' => 2]);

        $provider = new CountingProvider;
        $client = $this->client($provider);

        $vectors = $client->embed(['a', 'b', 'c', 'd', 'e']);

        $this->assertSame([2, 2, 1], $provider->batches);
        $this->assertCount(5, $vectors);
    }

    public function test_progress_is_reported_after_every_batch(): void
    {
        config(['rag.embedding_batch_size' => 2]);

        $seen = [];

        $this->client(new CountingProvider)->embed(['a', 'b', 'c'], function (int $done, int $total) use (&$seen): void {
            $seen[] = [$done, $total];
        });

        $this->assertSame([[2, 3], [3, 3]], $seen);
    }

    public function test_oversized_inputs_are_truncated(): void
    {
        config(['rag.embedding_batch_size' => 64]);

        $provider = new CountingProvider;
        $client = $this->client($provider);

        $client->embed([str_repeat('x', 40_000)]);

        $this->assertSame([1], $provider->batches);
        $this->assertSame(32_000, $provider->firstLength);
    }

    public function test_an_empty_input_list_costs_nothing(): void
    {
        $provider = new CountingProvider;

        $this->assertSame([], $this->client($provider)->embed([]));
        $this->assertSame([], $provider->batches);
    }

    public function test_a_query_embedding_is_reused_for_the_same_words(): void
    {
        $provider = new CountingProvider;
        $client = $this->client($provider);

        $first = $client->embedOne('When are invoices due?');
        $second = $client->embedOne('When are invoices due?');

        $this->assertSame($first, $second);
        $this->assertCount(1, $provider->batches, 'the same question must not be embedded twice');

        $client->embedOne('How long do refunds take?');

        $this->assertCount(2, $provider->batches);
    }

    public function test_a_progress_callback_bypasses_the_query_cache(): void
    {
        $provider = new CountingProvider;
        $client = $this->client($provider);

        $client->embedOne('When are invoices due?');
        $client->embedOne('When are invoices due?', static function (int $done, int $total): void {});

        $this->assertCount(2, $provider->batches, 'a caller waiting on progress must hit the provider');
    }

    private function client(EmbeddingProvider $provider): EmbeddingClient
    {
        return new EmbeddingClient($provider);
    }

    /**
     * @param  list<float>  $vector
     */
    private function norm(array $vector): float
    {
        $sum = 0.0;

        foreach ($vector as $value) {
            $sum += $value * $value;
        }

        return sqrt($sum);
    }
}

class StaticProvider implements EmbeddingProvider
{
    /**
     * @param  list<float>  $vector
     */
    public function __construct(private readonly array $vector) {}

    public function embed(array $texts): array
    {
        return array_map(fn (string $text): array => $this->vector, $texts);
    }

    public function label(): string
    {
        return 'static';
    }
}

class CountingProvider implements EmbeddingProvider
{
    /**
     * @var list<int>
     */
    public array $batches = [];

    public int $firstLength = 0;

    public function embed(array $texts): array
    {
        $this->batches[] = count($texts);
        $this->firstLength = $this->firstLength ?: mb_strlen($texts[0] ?? '');

        return array_map(fn (string $text): array => [1.0, 0.0], $texts);
    }

    public function label(): string
    {
        return 'counting';
    }
}
