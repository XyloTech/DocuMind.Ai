<?php

namespace App\Enums;

enum ChatRole: string
{
    case User = 'user';

    case Assistant = 'assistant';

    case System = 'system';

    /**
     * Short label used in the chat transcript.
     */
    public function label(): string
    {
        return match ($this) {
            self::User => 'You',
            self::Assistant => 'DocuMind',
            self::System => 'System',
        };
    }

    /**
     * Whether this turn is replayed to the model as conversation history.
     */
    public function isHistory(): bool
    {
        return $this === self::User || $this === self::Assistant;
    }
}
