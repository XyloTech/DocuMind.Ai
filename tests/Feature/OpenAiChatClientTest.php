<?php

namespace Tests\Feature;

use App\Exceptions\ChatException;
use App\Services\Ai\OpenAiChatClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiChatClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'openai.api_key' => 'sk-test-key',
            'openai.chat_model' => 'gpt-4o-mini',
        ]);
    }

    public function test_it_streams_each_delta_and_returns_the_usage_numbers(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response($this->completionStream(), 200),
        ]);

        $deltas = [];

        $completion = app(OpenAiChatClient::class)->complete(
            [['role' => 'user', 'content' => 'Hello?']],
            function (string $delta) use (&$deltas): void {
                $deltas[] = $delta;
            },
        );

        $this->assertSame(['Hello ', 'world'], $deltas);
        $this->assertSame('Hello world', $completion->text);
        $this->assertSame('gpt-4o-mini', $completion->model);
        $this->assertSame(12, $completion->promptTokens);
        $this->assertSame(5, $completion->completionTokens);

        Http::assertSent(fn ($request): bool => $request['stream'] === true
            && $request['model'] === 'gpt-4o-mini'
            && $request['messages'][0]['content'] === 'Hello?');
    }

    public function test_a_failed_request_raises_a_chat_exception(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response(['error' => ['message' => 'nope']], 401),
        ]);

        $this->expectException(ChatException::class);
        $this->expectExceptionMessage('HTTP 401');

        app(OpenAiChatClient::class)->complete([['role' => 'user', 'content' => 'Hi']]);
    }

    public function test_an_interrupted_stream_is_reported_as_an_error(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response(
                'data: '.json_encode(['choices' => [['delta' => ['content' => 'partial']]]])."\n\n",
                200,
            ),
        ]);

        $this->expectException(ChatException::class);
        $this->expectExceptionMessage('interrupted');

        app(OpenAiChatClient::class)->complete([['role' => 'user', 'content' => 'Hi']]);
    }

    public function test_a_missing_api_key_is_reported_before_any_request(): void
    {
        config(['openai.api_key' => '']);

        Http::fake();

        try {
            app(OpenAiChatClient::class)->complete([['role' => 'user', 'content' => 'Hi']]);

            $this->fail('A ChatException was expected.');
        } catch (ChatException $exception) {
            $this->assertStringContainsString('No AI API key', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    private function completionStream(): string
    {
        $frames = [
            ['model' => 'gpt-4o-mini', 'choices' => [['delta' => ['content' => 'Hello ']]]],
            ['model' => 'gpt-4o-mini', 'choices' => [['delta' => ['content' => 'world']]]],
            ['usage' => ['prompt_tokens' => 12, 'completion_tokens' => 5]],
        ];

        $stream = '';

        foreach ($frames as $frame) {
            $stream .= 'data: '.json_encode($frame)."\n\n";
        }

        return $stream."data: [DONE]\n\n";
    }
}
