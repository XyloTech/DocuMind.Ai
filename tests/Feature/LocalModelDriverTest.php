<?php

namespace Tests\Feature;

use App\Services\Ai\ChatClient;
use App\Services\Ai\EmbeddingProvider;
use App\Services\Ai\FakeChatClient;
use App\Services\Ai\FakeEmbeddingProvider;
use App\Services\Ai\OpenAiChatClient;
use App\Services\Ai\OpenAiEmbeddingProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LocalModelDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_embeddings_are_requested_from_our_own_model_service(): void
    {
        config([
            'rag.ai_driver' => 'local',
            'ml.base_url' => 'http://ml:8090/v1',
            'ml.embedding_model' => 'BAAI/bge-small-en-v1.5',
        ]);

        Http::fake([
            'http://ml:8090/v1/embeddings' => Http::response([
                'data' => [['index' => 0, 'embedding' => [1.0, 0.0, 0.0]]],
            ]),
        ]);

        $vectors = app(EmbeddingProvider::class)->embed(['hello']);

        $this->assertSame([[1.0, 0.0, 0.0]], $vectors);
        $this->assertSame('local', app(EmbeddingProvider::class)->label());

        Http::assertSent(fn ($request): bool => $request->url() === 'http://ml:8090/v1/embeddings'
            && $request['model'] === 'BAAI/bge-small-en-v1.5'
            && $request['input'] === ['hello']);
    }

    public function test_chat_is_streamed_from_our_own_model_service(): void
    {
        config(['rag.ai_driver' => 'local']);

        Http::fake([
            'http://ml:8090/v1/chat/completions' => Http::response(
                'data: '.json_encode(['choices' => [['delta' => ['content' => 'Local']]]])."\n\n"
                .'data: '.json_encode([
                    'choices' => [['delta' => ['content' => ' answer']]],
                    'usage' => ['prompt_tokens' => 9, 'completion_tokens' => 2],
                ])."\n\n"
                ."data: [DONE]\n\n",
                200,
            ),
        ]);

        $deltas = [];

        $completion = app(ChatClient::class)->complete(
            [['role' => 'user', 'content' => 'hi']],
            function (string $delta) use (&$deltas): void {
                $deltas[] = $delta;
            },
        );

        $this->assertSame(['Local', ' answer'], $deltas);
        $this->assertSame('Local answer', $completion->text);
        $this->assertSame('local', app(ChatClient::class)->label());
    }

    public function test_the_local_driver_reuses_the_openai_compatible_client(): void
    {
        config(['rag.ai_driver' => 'local']);

        $this->assertInstanceOf(OpenAiChatClient::class, app(ChatClient::class));
        $this->assertInstanceOf(OpenAiEmbeddingProvider::class, app(EmbeddingProvider::class));
    }

    public function test_autodetect_prefers_local_when_the_service_is_enabled(): void
    {
        config(['rag.ai_driver' => '', 'ml.enabled' => true, 'openai.api_key' => '']);

        $this->assertInstanceOf(OpenAiEmbeddingProvider::class, app(EmbeddingProvider::class));
        $this->assertSame('local', app(EmbeddingProvider::class)->label());
    }

    public function test_autodetect_falls_back_to_openai_when_local_is_disabled(): void
    {
        config(['rag.ai_driver' => '', 'ml.enabled' => false, 'openai.api_key' => 'sk-test']);

        $this->assertInstanceOf(OpenAiEmbeddingProvider::class, app(EmbeddingProvider::class));
        $this->assertSame('openai', app(EmbeddingProvider::class)->label());
    }

    public function test_autodetect_falls_back_to_the_offline_driver_without_local_or_a_key(): void
    {
        config(['rag.ai_driver' => '', 'ml.enabled' => false, 'openai.api_key' => '']);

        $this->assertInstanceOf(FakeEmbeddingProvider::class, app(EmbeddingProvider::class));
        $this->assertInstanceOf(FakeChatClient::class, app(ChatClient::class));
    }
}
