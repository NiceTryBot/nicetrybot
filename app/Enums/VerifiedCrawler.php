<?php

namespace App\Enums;

/**
 * A crawler whose operator publishes the IP ranges it crawls from, so its traffic can be verified by IP
 * rather than trusted by user agent, which anyone can copy.
 */
enum VerifiedCrawler: string
{
    case OpenAiGptBot = 'openai-gptbot';

    /**
     * Get the crawler's name as people say it.
     */
    public function label(): string
    {
        return match ($this) {
            self::OpenAiGptBot => __('OpenAI GPTBot'),
        };
    }

    /**
     * Get the address of the operator's published list of ranges.
     */
    public function rangesUrl(): string
    {
        return match ($this) {
            self::OpenAiGptBot => 'https://openai.com/gptbot.json',
        };
    }
}
