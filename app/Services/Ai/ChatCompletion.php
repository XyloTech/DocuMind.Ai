<?php

namespace App\Services\Ai;

/**
 * One generated answer, plus the usage numbers we persist for cost tracking.
 */
final readonly class ChatCompletion
{
    public function __construct(
        public string $text,
        public string $model,
        public int $promptTokens = 0,
        public int $completionTokens = 0,
    ) {}
}
