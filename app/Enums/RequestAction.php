<?php

namespace App\Enums;

/**
 * What the edge did with a request. Stored as a smallint; URLs and edge logs use the slug.
 */
enum RequestAction: int
{
    case Passed = 1;
    case Observed = 2;
    case Diverted = 3;
    case Blocked = 4;

    /**
     * Get the action from the slug used in URLs and edge logs (e.g. "observed").
     */
    public static function fromSlug(?string $slug): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->slug() === $slug) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Get the slug used in URLs and edge logs.
     */
    public function slug(): string
    {
        return strtolower($this->name);
    }

    /**
     * Get the display label for the action.
     */
    public function label(): string
    {
        return match ($this) {
            self::Passed => __('Passed'),
            self::Observed => __('Observed'),
            self::Diverted => __('In the gym'),
            self::Blocked => __('Blocked'),
        };
    }

    /**
     * Get the Flux badge color for the action.
     */
    public function color(): string
    {
        return match ($this) {
            self::Passed => 'zinc',
            self::Observed => 'sky',
            self::Diverted => 'amber',
            self::Blocked => 'rose',
        };
    }

    /**
     * Get the icon for the action.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Passed => 'check',
            self::Observed => 'eye',
            self::Diverted => 'arrow-uturn-right',
            self::Blocked => 'no-symbol',
        };
    }

    /**
     * Determine if the request was tagged as suspicious.
     */
    public function isSuspicious(): bool
    {
        return $this !== self::Passed;
    }

    /**
     * Determine if the request was kept away from the origin.
     */
    public function isKeptOffOrigin(): bool
    {
        return in_array($this, [self::Diverted, self::Blocked], true);
    }

    /**
     * Get the actions that tag a request as suspicious.
     *
     * @return array<int, self>
     */
    public static function suspicious(): array
    {
        return array_values(array_filter(self::cases(), fn (self $action) => $action->isSuspicious()));
    }

    /**
     * Get the actions that keep a request away from the origin.
     *
     * @return array<int, self>
     */
    public static function keptOffOrigin(): array
    {
        return array_values(array_filter(self::cases(), fn (self $action) => $action->isKeptOffOrigin()));
    }
}
