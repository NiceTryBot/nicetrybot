<?php

namespace NiceTry\Tarpit\Enums;

/**
 * Why the edge put a request in a tarpit, as it logs it (tarpit_why).
 */
enum TarpitReason: int
{
    case Rule = 1;
    case Verdict = 2;
    case NoShow = 3;
    case NotFound = 4;
    case Burst = 5;
    case Client = 6;

    /**
     * Get the reason the edge logged, by its name there.
     */
    public static function fromSlug(?string $slug): ?self
    {
        return match ($slug) {
            'rule' => self::Rule,
            'verdict' => self::Verdict,
            'no_show' => self::NoShow,
            'not_found' => self::NotFound,
            'burst' => self::Burst,
            'client' => self::Client,
            default => null,
        };
    }

    /**
     * Describe the reason, to finish "in the tarpit because…".
     */
    public function describe(): string
    {
        return match ($this) {
            self::Rule => __('the request matched one of its rules'),
            self::Verdict => __('one of its rules gave the client a verdict'),
            self::NoShow => __('the client left the gym without running its script'),
            self::NotFound => __('the client collected too many 404s'),
            self::Burst => __('the client sent a burst of requests'),
            self::Client => __('the client was on its list'),
        };
    }
}
