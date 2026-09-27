<?php

namespace App\Services\Ai;

use App\Exceptions\ChatException;
use App\Services\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Streams a chat completion from an OpenAI-compatible /chat/completions API.
 *
 * The request is always made with `stream: true` so the answer can be pushed
 * to the browser token by token; callers that do not want to stream simply
 * omit the $onDelta callback and collect the returned text.
 */
class OpenAiChatClient implements ChatClient
{
    private const int READ_CHUNK_BYTES = 8192;

    /**
     * A black-holed TCP handshake must fail fast: the full `timeout` only starts
     * counting once the connection is up, so without this a dead provider costs
     * the whole read timeout before the first byte.
     */
    private const int CONNECT_TIMEOUT_SECONDS = 5;

    /**
     * @param  array{base_url?: string, api_key?: string, model?: string, label?: string, timeout?: int}  $options
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly array $options = [],
    ) {}

    public function label(): string
    {
        return $this->option('label', 'openai');
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  (callable(string $delta): void)|null  $onDelta
     *
     * @throws ChatException
     */
    public function complete(array $messages, ?callable $onDelta = null): ChatCompletion
    {
        $key = $this->apiKey();

        if ($key === '') {
            throw ChatException::missingApiKey();
        }

        try {
            $response = Http::withToken($key)
                ->acceptJson()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout($this->timeout())
                ->withOptions(['stream' => true])
                ->post($this->baseUrl().'/chat/completions', [
                    'model' => $this->model(),
                    'messages' => $messages,
                    'stream' => true,
                    'stream_options' => ['include_usage' => true],
                    'temperature' => 0.2,
                ]);
        } catch (ConnectionException) {
            throw ChatException::interrupted();
        }

        if (! $response->successful()) {
            throw ChatException::requestFailed($response->status(), $response->body());
        }

        return $this->consume($response, $onDelta);
    }

    /**
     * Read Server-Sent Events off the response body as they arrive.
     *
     * @param  (callable(string $delta): void)|null  $onDelta
     *
     * @throws ChatException
     */
    private function consume(Response $response, ?callable $onDelta): ChatCompletion
    {
        $state = [
            'finished' => false,
            'text' => '',
            'model' => $this->model(),
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
        ];

        $stream = $response->toPsrResponse()->getBody();
        $buffer = '';

        try {
            while (! $state['finished'] && ! $stream->eof()) {
                $chunk = $stream->read(self::READ_CHUNK_BYTES);

                if ($chunk === '' || $chunk === false) {
                    break;
                }

                $buffer .= $chunk;

                while (($position = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $position));
                    $buffer = substr($buffer, $position + 1);

                    $this->apply($line, $onDelta, $state);

                    if ($state['finished']) {
                        break 2;
                    }
                }
            }

            if (! $state['finished'] && trim($buffer) !== '') {
                $this->apply(trim($buffer), $onDelta, $state);
            }
        } finally {
            // Release the underlying socket/curl handle; abandoning it leaks a
            // connection per streamed answer.
            $response->toPsrResponse()->getBody()->close();
        }

        // Without `data: [DONE]` there is no way to know the answer is whole.
        // Treating a closed socket as success would silently present a truncated
        // answer as complete and keep a credit the user never finished paying for.
        if (! $state['finished']) {
            throw ChatException::interrupted();
        }

        if ($state['text'] === '') {
            throw ChatException::malformed();
        }

        $this->applyTokenFallback($state);

        return new ChatCompletion(
            text: $state['text'],
            model: $state['model'],
            promptTokens: $state['prompt_tokens'],
            completionTokens: $state['completion_tokens'],
        );
    }

    /**
     * Servers that omit the usage block still need a token estimate for cost
     * tracking, so fall back to the same rough heuristic as the fake driver.
     *
     * @param  array{finished: bool, text: string, model: string, prompt_tokens: int, completion_tokens: int}  $state
     */
    private function applyTokenFallback(array &$state): void
    {
        if ($state['prompt_tokens'] === 0 || $state['completion_tokens'] === 0) {
            $state['completion_tokens'] = $state['completion_tokens'] > 0
                ? $state['completion_tokens']
                : max(1, (int) ceil(mb_strlen($state['text']) / 4));
        }
    }

    /**
     * Fold a single `data:` line into the running state.
     *
     * @param  (callable(string $delta): void)|null  $onDelta
     * @param  array{finished: bool, text: string, model: string, prompt_tokens: int, completion_tokens: int}  $state
     */
    private function apply(string $line, ?callable $onDelta, array &$state): void
    {
        if (! str_starts_with($line, 'data:')) {
            return;
        }

        $payload = trim(substr($line, 5));

        if ($payload === '[DONE]') {
            $state['finished'] = true;

            return;
        }

        $data = json_decode($payload, true);

        if (! is_array($data)) {
            return;
        }

        if (isset($data['model']) && is_string($data['model'])) {
            $state['model'] = $data['model'];
        }

        $delta = $data['choices'][0]['delta']['content'] ?? null;

        if (is_string($delta) && $delta !== '') {
            $state['text'] .= $delta;

            if ($onDelta !== null) {
                $onDelta($delta);
            }
        }

        if (isset($data['usage']) && is_array($data['usage'])) {
            $state['prompt_tokens'] = (int) ($data['usage']['prompt_tokens'] ?? $state['prompt_tokens']);
            $state['completion_tokens'] = (int) ($data['usage']['completion_tokens'] ?? $state['completion_tokens']);
        }
    }

    private function apiKey(): string
    {
        $key = $this->option('api_key');

        if ($key !== null && $key !== '') {
            return $key;
        }

        $key = $this->settings->getString('openai_api_key', '');

        if ($key === '') {
            $key = (string) config('openai.api_key', '');
        }

        return $key;
    }

    private function baseUrl(): string
    {
        $url = $this->option('base_url');

        return rtrim($url ?? (string) config('openai.base_url'), '/');
    }

    private function model(): string
    {
        return $this->option('model') ?? (string) config('openai.chat_model');
    }

    private function timeout(): int
    {
        return (int) ($this->options['timeout'] ?? config('openai.timeout', 60));
    }

    private function option(string $key, ?string $default = null): ?string
    {
        $value = $this->options[$key] ?? $default;

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
