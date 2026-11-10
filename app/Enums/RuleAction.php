<?php

namespace App\Enums;

/**
 * What a rule does to a subject it matches. Stored as a smallint.
 */
enum RuleAction: int
{
    case Block = 1;
    case Tag = 2;
    case Fingerprint = 3;

    /**
     * Get the display label for the action.
     */
    public function label(): string
    {
        return match ($this) {
            self::Block => __('Block'),
            self::Tag => __('Tag'),
            self::Fingerprint => __('Fingerprint'),
        };
    }

    /**
     * Get the Flux badge color for the action.
     */
    public function color(): string
    {
        return match ($this) {
            self::Block => 'rose',
            self::Tag => 'sky',
            self::Fingerprint => 'teal',
        };
    }
}
