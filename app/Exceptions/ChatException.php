<?php

namespace App\Exceptions;

use RuntimeException;

class ChatException extends RuntimeException
{
    public static function missingApiKey(): self
    {
        return new self('No AI API key configured. Add one in Admin → Settings, or set RAG_AI_DRIVER=fake for a demo.');
    }

    public static function requestFailed(int $status, string $body): self
    {
        return new self('The chat request failed (HTTP '.$status.'). '.mb_substr($body, 0, 300));
    }

    public static function interrupted(): self
    {
        return new self('The connection to the AI provider was interrupted before the answer finished.');
    }

    public static function malformed(): self
    {
        return new self('The AI provider returned an unexpected response.');
    }
}
