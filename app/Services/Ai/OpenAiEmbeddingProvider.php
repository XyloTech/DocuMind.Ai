<?php

namespace App\Services\Ai;

use App\Exceptions\EmbeddingException;
use App\Services\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class OpenAiEmbeddingProvider implements EmbeddingProvider
{
    private const int MAX_ATTEMPTS = 4;

    /** See OpenAiChatClient: connect first, then start the full timeout. */
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

    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $key = $this->apiKey();

        if ($key === '') {
            throw EmbeddingException::missingApiKey();
        }

        $response = $this->postWithRetry($key, $texts);

        if (! $response->successful()) {
            throw EmbeddingException::requestFailed($response->status(), $response->body());
        }

        return $this->order($response->json('data') ?? [], count($texts));
    }

    /**
     * @param  list<string>  $texts
     */
    private function postWithRetry(string $key, array $texts): Response
    {
        $delayMicroseconds = 500_000;
        $attempt = 0;
        $error = '';

        while (true) {
            $attempt++;

            try {
                $response = Http::withToken($key)
                    ->acceptJson()
                    ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                    ->timeout((int) ($this->options['timeout'] ?? config('openai.timeout', 60)))
                    ->post($this->baseUrl().'/embeddings', [
                        'model' => $this->model(),
                        'input' => $texts,
                    ]);
            } catch (ConnectionException $exception) {
                $response = null;
                $error = $exception->getMessage();
            }

            if ($response !== null && $response->successful()) {
                return $response;
            }

            $retryable = $response === null
                || $response->status() === 429
                || $response->serverError();

            if (! $retryable || $attempt >= self::MAX_ATTEMPTS) {
                if ($response === null) {
                    throw EmbeddingException::requestFailed(0, $error);
                }

                return $response;
            }

            $retryAfter = $response->header('Retry-After');
            usleep(is_numeric($retryAfter) ? ((int) $retryAfter) * 1_000_000 : $delayMicroseconds);

            $delayMicroseconds *= 2;
        }
    }

    /**
     * The API guarantees results in request order, but sorting by `index`
     * keeps us honest if a provider ever does not.
     *
     * @param  array<int, array{index: int, embedding?: array<int, float>}>  $data
     * @return list<list<float>>
     *
     * @throws EmbeddingException
     */
    private function order(array $data, int $expected): array
    {
        if (count($data) !== $expected) {
            throw EmbeddingException::mismatchedResponse($expected, count($data));
        }

        usort($data, fn (array $a, array $b): int => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        $vectors = [];

        foreach ($data as $row) {
            $vectors[] = array_map('floatval', $row['embedding'] ?? []);
        }

        return $vectors;
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
        return $this->option('model') ?? (string) config('openai.embedding_model');
    }

    private function option(string $key, ?string $default = null): ?string
    {
        $value = $this->options[$key] ?? $default;

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
