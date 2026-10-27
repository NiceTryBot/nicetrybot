<?php

namespace App\Enums;

/**
 * Where a visitor goes after clearing every obstacle. Stored as a smallint. When routines stack, the
 * strictest exit wins, so the cases are ordered from most to least lenient.
 */
enum GymExit: int
{
    case Continue = 1;
    case NotFound = 2;
    case Block = 3;

    /**
     * Get the strictest of several exits.
     *
     * @param  iterable<self>  $exits
     */
    public static function strictest(iterable $exits): self
    {
        $strictest = self::Continue;

        foreach ($exits as $exit) {
            if ($exit->value > $strictest->value) {
                $strictest = $exit;
            }
        }

        return $strictest;
    }

    /**
     * Get the display label for the exit.
     */
    public function label(): string
    {
        return match ($this) {
            self::Continue => __('Continue to their page'),
            self::NotFound => __('Show a not-found page'),
            self::Block => __('Turn them away'),
        };
    }

    /**
     * Get a short explanation of the exit.
     */
    public function description(): string
    {
        return match ($this) {
            self::Continue => __('They get a pass and land where they were going.'),
            self::NotFound => __('The gym answers 404 itself. Your origin never sees the request.'),
            self::Block => __('The gym answers 403. Use it for traffic you never want, after the work.'),
        };
    }

    /**
     * Get the icon for the exit.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Continue => 'arrow-right-circle',
            self::NotFound => 'document-magnifying-glass',
            self::Block => 'no-symbol',
        };
    }

    /**
     * Get the Flux badge color for the exit.
     */
    public function color(): string
    {
        return match ($this) {
            self::Continue => 'emerald',
            self::NotFound => 'amber',
            self::Block => 'rose',
        };
    }

    /**
     * Get the HTTP status the gym answers with, when it answers itself.
     */
    public function status(): ?int
    {
        return match ($this) {
            self::Continue => null,
            self::NotFound => 404,
            self::Block => 403,
        };
    }
}
