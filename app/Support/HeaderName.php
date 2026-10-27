<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Tidies an HTTP header name typed or pasted into a form.
 */
class HeaderName
{
    /**
     * Strip surrounding whitespace (including non-breaking and zero-width spaces a paste can bring along)
     * and turn typographic dashes into plain hyphens: " X‑Forwarded‑Host " becomes "X-Forwarded-Host".
     */
    public static function normalize(string $name): string
    {
        return (string) preg_replace('/[\x{2010}-\x{2015}\x{2212}]/u', '-', Str::trim($name));
    }

    /**
     * Tidy a comma-separated list of header names the same way, dropping empty entries and joining them
     * with ", ": "X‑Domain,, User-Custom-Domain " becomes "X-Domain, User-Custom-Domain".
     */
    public static function normalizeList(string $names): string
    {
        return implode(', ', array_filter(
            array_map(self::normalize(...), explode(',', $names)),
            fn (string $name) => $name !== '',
        ));
    }

    /**
     * Split a stored comma-separated list of header names into its names, in order.
     *
     * @return list<string>
     */
    public static function split(?string $names): array
    {
        return array_values(array_filter(
            array_map(Str::trim(...), explode(',', (string) $names)),
            fn (string $name) => $name !== '',
        ));
    }
}
