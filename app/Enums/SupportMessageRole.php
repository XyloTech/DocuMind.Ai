<?php

namespace App\Enums;

enum SupportMessageRole: string
{
    case User = 'user';

    case Agent = 'agent';

    case System = 'system';

    /**
     * Speaker label shown next to a message in both transcripts.
     */
    public function label(): string
    {
        return match ($this) {
            self::User => 'You',
            self::Agent => 'Support',
            self::System => 'System',
        };
    }
}
