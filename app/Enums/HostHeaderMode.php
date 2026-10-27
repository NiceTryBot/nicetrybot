<?php

namespace App\Enums;

enum HostHeaderMode: string
{
    case PassThrough = 'pass_through';
    case Rewrite = 'rewrite';

    /**
     * Get the display label for the mode.
     */
    public function label(): string
    {
        return match ($this) {
            self::PassThrough => __('Pass the visitor\'s host through'),
            self::Rewrite => __('Rewrite to the origin\'s host'),
        };
    }

    /**
     * Get a short explanation of the mode.
     */
    public function description(): string
    {
        return match ($this) {
            self::PassThrough => __('Your origin receives the same Host header the visitor sent.'),
            self::Rewrite => __('Useful when one origin serves many domains, like a shared app behind a gateway.'),
        };
    }
}
