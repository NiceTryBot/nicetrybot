<?php

namespace NiceTry\Tarpit\Enums;

/**
 * What a tarpit does with a request it should hold while it's already holding all it may.
 */
enum TarpitWhenFull: int
{
    case Reject = 1;
    case Pass = 2;
    case HangUp = 3;

    /**
     * The name the edge knows it by.
     */
    public function slug(): string
    {
        return match ($this) {
            self::Reject => 'reject',
            self::Pass => 'pass',
            self::HangUp => 'hangup',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Reject => __('Answer 429 Too Many Requests'),
            self::Pass => __('Let it through'),
            self::HangUp => __('Hang up'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Reject => __('Right away, with Retry-After. Nothing reaches your origin.'),
            self::Pass => __('On to the gym and your origin, as if the tarpit weren\'t there.'),
            self::HangUp => __('Close the connection without an answer (HTTP/1; an empty 404 on HTTP/2).'),
        };
    }
}
