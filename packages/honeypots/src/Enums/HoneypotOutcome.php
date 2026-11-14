<?php

namespace NiceTry\Honeypots\Enums;

/**
 * What came of a trapped request: the honeypot answered it, or it couldn't in time and the site's own
 * 404 went out.
 */
enum HoneypotOutcome: int
{
    case Served = 1;
    case Failed = 2;

    /**
     * The outcome the edge logged by name, or null for one it doesn't know.
     */
    public static function fromSlug(?string $slug): ?self
    {
        return match ($slug) {
            'served' => self::Served,
            'failed' => self::Failed,
            default => null,
        };
    }
}
