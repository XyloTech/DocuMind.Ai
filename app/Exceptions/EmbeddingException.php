<?php

namespace App\Exceptions;

use RuntimeException;

class EmbeddingException extends RuntimeException
{
    public static function missingApiKey(): self
    {
        return new self('No AI API key configured. Add one in Admin → Settings, or set RAG_AI_DRIVER=fake for a demo.');
    }

    public static function requestFailed(int $status, string $body): self
    {
        return new self('The embedding request failed (HTTP '.$status.'). '.mb_substr($body, 0, 300));
    }

    public static function mismatchedResponse(int $expected, int $received): self
    {
        return new self("The embedding provider returned {$received} vectors for {$expected} inputs.");
    }

    public static function documentTooLarge(int $estimatedTokens, int $limit): self
    {
        return new self(
            "This document is too large to process (about {$estimatedTokens} tokens, limit {$limit}). Split it into smaller files."
        );
    }
}
