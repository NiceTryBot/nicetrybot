<?php

namespace App\Enums;

/**
 * An AI provider a user can connect with their own API key.
 */
enum AiProvider: string
{
    case Anthropic = 'anthropic';

    /**
     * Get the provider's name.
     */
    public function label(): string
    {
        return match ($this) {
            self::Anthropic => __('Anthropic'),
        };
    }

    /**
     * Determine if a value looks like one of this provider's API keys.
     */
    public function accepts(string $key): bool
    {
        return match ($this) {
            self::Anthropic => preg_match('/^sk-ant-[A-Za-z0-9_-]{20,}$/', $key) === 1,
        };
    }
}
