<?php

namespace App\Services\Ai;

use App\Exceptions\ChatException;

interface ChatClient
{
    /**
     * The line the model is instructed to fall back to when the context does
     * not contain the answer.
     */
    public const string REFUSAL = 'I do not have enough confirmed support information to answer that.';

    public function label(): string;

    /**
     * Generate an answer for an assembled prompt.
     *
     * When $onDelta is provided the text is delivered piece by piece as the
     * provider produces it; the full text is always returned as well.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @param  (callable(string $delta): void)|null  $onDelta
     *
     * @throws ChatException
     */
    public function complete(array $messages, ?callable $onDelta = null): ChatCompletion;
}
