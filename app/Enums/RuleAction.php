<?php

namespace App\Enums;

/**
 * What a rule does to a subject it matches. Stored as a smallint.
 */
enum RuleAction: int
{
    case Ban = 1;
    case Tag = 2;

    /**
     * Get the display label for the action.
     */
    public function label(): string
    {
        return match ($this) {
            self::Ban => __('Ban'),
            self::Tag => __('Tag'),
        };
    }

    /**
     * Get the Flux badge color for the action.
     */
    public function color(): string
    {
        return match ($this) {
            self::Ban => 'rose',
            self::Tag => 'sky',
        };
    }
}
