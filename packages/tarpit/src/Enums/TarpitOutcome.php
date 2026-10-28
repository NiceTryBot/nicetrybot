<?php

namespace NiceTry\Tarpit\Enums;

/**
 * What came of a request in a tarpit, as the edge logs it (tarpit_outcome).
 */
enum TarpitOutcome: int
{
    /**
     * One of the tarpit's free tries: not held.
     */
    case Free = 1;

    /**
     * Held for as long as its try called for.
     */
    case Held = 2;

    /**
     * The tarpit (or the edge) was holding all it may: answered at once, as the tarpit says.
     */
    case Full = 3;

    /**
     * The client gave up while it was held.
     */
    case Left = 4;

    /**
     * Get the outcome the edge logged, by its name there.
     */
    public static function fromSlug(?string $slug): ?self
    {
        return match ($slug) {
            'free' => self::Free,
            'held' => self::Held,
            'full' => self::Full,
            'left' => self::Left,
            default => null,
        };
    }
}
