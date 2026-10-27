<?php

namespace App\Enums;

enum HstsPolicy: string
{
    case Off = 'off';
    case On = 'on';
    case Preload = 'preload';

    /**
     * Get the display label for the policy.
     */
    public function label(): string
    {
        return match ($this) {
            self::Off => __('Off'),
            self::On => __('On, for a year'),
            self::Preload => __('On, with subdomains and preload'),
        };
    }

    /**
     * Get a short explanation of the policy.
     */
    public function description(): string
    {
        return match ($this) {
            self::Off => __('No Strict-Transport-Security header. Browsers may still try plain http first.'),
            self::On => __('Browsers that visit once stick to https for a year.'),
            self::Preload => __('Also covers every subdomain and asks to be built into browsers. Hard to undo: only choose it if every subdomain serves https.'),
        };
    }

    /**
     * Get the Strict-Transport-Security value the edge sends, or null for none.
     */
    public function headerValue(): ?string
    {
        return match ($this) {
            self::Off => null,
            self::On => 'max-age=31536000',
            self::Preload => 'max-age=31536000; includeSubDomains; preload',
        };
    }
}
