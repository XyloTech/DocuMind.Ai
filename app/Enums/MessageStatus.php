<?php

namespace App\Enums;

enum MessageStatus: string
{
    case Streaming = 'streaming';

    case Complete = 'complete';

    case Failed = 'failed';

    /**
     * Whether the message is in a final state and safe to render as an answer.
     */
    public function isTerminal(): bool
    {
        return $this === self::Complete || $this === self::Failed;
    }
}
