<?php

namespace NiceTry\Rewrites\Enums;

/**
 * Which responses a rewrite reads.
 */
enum RewriteContent: int
{
    case Html = 1;
    case Text = 2;

    /**
     * The name the edge knows it by.
     */
    public function slug(): string
    {
        return match ($this) {
            self::Html => 'html',
            self::Text => 'text',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Html => __('HTML pages'),
            self::Text => __('Any text response'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Html => __('Pages only, so scripts and data are left alone.'),
            self::Text => __('HTML, CSS, JavaScript, JSON, XML and plain text. Careful: a change in a script can break it.'),
        };
    }
}
