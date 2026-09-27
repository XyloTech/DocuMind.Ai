<?php

namespace App\Enums;

enum UserRole: string
{
    case User = 'user';

    case Admin = 'admin';

    case Support = 'support';

    case Analyst = 'analyst';

    /**
     * Human readable label for the account menu and admin tables.
     */
    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Support => 'Support',
            self::Analyst => 'Analyst',
            self::User => 'Account owner',
        };
    }
}
