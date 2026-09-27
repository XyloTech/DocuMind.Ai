<?php

namespace App\Enums;

/**
 * Presentation weight of a notification. Drives the toast style, the dot
 * colour in the centre, and the live-region politeness (errors announce
 * assertively, everything else waits its turn).
 */
enum NotificationSeverity: string
{
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Info',
            self::Success => 'Success',
            self::Warning => 'Warning',
            self::Error => 'Error',
        };
    }
}
