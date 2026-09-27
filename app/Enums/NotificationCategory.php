<?php

namespace App\Enums;

/**
 * The buckets the notification centre filters and groups by. Kept small on
 * purpose — each one is a filter chip a human has to recognise at a glance.
 */
enum NotificationCategory: string
{
    case Conversation = 'conversation';
    case Lead = 'lead';
    case Knowledge = 'knowledge';
    case Widget = 'widget';
    case Team = 'team';
    case Billing = 'billing';
    case Security = 'security';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Conversation => 'Conversations',
            self::Lead => 'Visitor leads',
            self::Knowledge => 'Knowledge',
            self::Widget => 'Widget',
            self::Team => 'Team',
            self::Billing => 'Billing & credits',
            self::Security => 'Security',
            self::System => 'System',
        };
    }

    /**
     * Icon key resolved by the notification views; names map to the
     * stroke SVG paths in partials/notifications.blade.php.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Conversation => 'chat',
            self::Lead => 'mail',
            self::Knowledge => 'document',
            self::Widget => 'widget',
            self::Team => 'users',
            self::Billing => 'card',
            self::Security => 'shield',
            self::System => 'gear',
        };
    }
}
