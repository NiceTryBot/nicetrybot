<?php

namespace App\Enums;

/**
 * A titled group of links in the sidebar, drawn in this order after the links that have none.
 */
enum NavGroup: string
{
    case Verify = 'verify';
    case Timeouts = 'timeouts';
    case Tags = 'tags';
    case Analytics = 'analytics';

    public function label(): string
    {
        return match ($this) {
            self::Verify => __('Verify'),
            self::Timeouts => __('Timeouts'),
            self::Tags => __('Tags'),
            self::Analytics => __('Analytics'),
        };
    }
}
