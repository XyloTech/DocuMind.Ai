<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Pending = 'pending';

    case Processing = 'processing';

    case Processed = 'processed';

    case Failed = 'failed';

    /**
     * Short label for the status badge on a document card.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Queued',
            self::Processing => 'Processing',
            self::Processed => 'Ready',
            self::Failed => 'Failed',
        };
    }

    /**
     * Whether the background pipeline has finished with this document.
     */
    public function isTerminal(): bool
    {
        return $this === self::Processed || $this === self::Failed;
    }
}
