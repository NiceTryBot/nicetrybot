<?php

namespace App\Enums;

/**
 * How far a visitor got in the gym. Stored as a smallint. A session still training after the idle
 * limit counts as given up (see GymSession::hasGivenUp), without a status of its own.
 */
enum GymSessionStatus: int
{
    case Training = 1;
    case Cleared = 2;
    case TurnedAway = 3;

    /**
     * Get the display label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Training => __('Training'),
            self::Cleared => __('Cleared'),
            self::TurnedAway => __('Turned away'),
        };
    }

    /**
     * Get the Flux badge color for the status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Training => 'amber',
            self::Cleared => 'emerald',
            self::TurnedAway => 'rose',
        };
    }
}
