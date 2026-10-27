<?php

namespace App\Enums;

enum ProtectionMode: string
{
    case Off = 'off';
    case Observe = 'observe';
    case Enforce = 'enforce';

    /**
     * Get the display label for the mode.
     */
    public function label(): string
    {
        return match ($this) {
            self::Off => __('Off'),
            self::Observe => __('Observe'),
            self::Enforce => __('Enforce'),
        };
    }

    /**
     * Get a short explanation of the mode.
     */
    public function description(): string
    {
        return match ($this) {
            self::Off => __('Proxy and log only. No tagging.'),
            self::Observe => __('Tag suspicious traffic, but let it through.'),
            self::Enforce => __('Send tagged traffic to the gym or block it.'),
        };
    }

    /**
     * Get the Flux badge color for the mode.
     */
    public function color(): string
    {
        return match ($this) {
            self::Off => 'zinc',
            self::Observe => 'sky',
            self::Enforce => 'rose',
        };
    }
}
