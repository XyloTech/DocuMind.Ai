<?php

namespace App\Enums;

enum WidgetPosition: string
{
    case BottomLeft = 'bottom-left';

    case BottomRight = 'bottom-right';

    /**
     * CSS side for the launcher and panel.
     */
    public function side(): string
    {
        return $this === self::BottomRight ? 'right' : 'left';
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $position): string => $position->value, self::cases());
    }
}
