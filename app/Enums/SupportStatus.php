<?php

namespace App\Enums;

enum SupportStatus: string
{
    case Open = 'open';

    case Assigned = 'assigned';

    case Resolved = 'resolved';

    case Closed = 'closed';

    /**
     * Short label used on the support page and the admin inbox.
     */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'Waiting for an agent',
            self::Assigned => 'In progress',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    /**
     * A conversation still accepting messages from either side.
     */
    public function isLive(): bool
    {
        return $this === self::Open || $this === self::Assigned;
    }
}
