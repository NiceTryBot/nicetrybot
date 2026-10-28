<?php

namespace NiceTry\Tarpit\Enums;

/**
 * What happens to a request once a tarpit has held it.
 */
enum TarpitAfterWait: int
{
    case Continue = 1;
    case NotFound = 2;
    case HangUp = 3;

    /**
     * The name the edge knows it by.
     */
    public function slug(): string
    {
        return match ($this) {
            self::Continue => 'continue',
            self::NotFound => 'not_found',
            self::HangUp => 'hangup',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Continue => __('Go on'),
            self::NotFound => __('Answer 404'),
            self::HangUp => __('Hang up'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Continue => __('On to the gym (if a routine takes it) and your origin.'),
            self::NotFound => __('An empty 404 from the edge. Nothing reaches your origin.'),
            self::HangUp => __('Close the connection without an answer (HTTP/1; an empty 404 on HTTP/2).'),
        };
    }
}
