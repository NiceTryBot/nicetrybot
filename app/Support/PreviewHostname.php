<?php

namespace App\Support;

use App\Models\Domain;

/**
 * Names each domain's preview: its hostname folded into one label under the preview zone, like
 * shop.example.com → shop-example-com.preview.example. The edge serves the preview as the domain
 * itself, so a site can be tried through NiceTryBot before its DNS moves. The zone is config
 * (edge.preview_zone); without one, previews are off and no hostname is a preview.
 */
class PreviewHostname
{
    /**
     * The longest a DNS label may be.
     */
    private const int MAX_LABEL_LENGTH = 63;

    /**
     * Get the zone every preview lives under, whose wildcard DNS record points at the edge, or null
     * when previews are off.
     */
    public static function zone(): ?string
    {
        $zone = config('edge.preview_zone');

        return is_string($zone) && $zone !== '' ? $zone : null;
    }

    /**
     * Determine if domains get previews here.
     */
    public static function enabled(): bool
    {
        return self::zone() !== null;
    }

    /**
     * Pick a new domain's preview hostname, or null for a domain that already lives under the preview
     * zone, or for every domain when previews are off. When another domain holds the name (as its
     * preview or its own hostname), a random tail tells them apart.
     */
    public static function generate(string $hostname): ?string
    {
        $zone = self::zone();

        if ($zone === null || self::isPreview($hostname)) {
            return null;
        }

        $preview = self::label($hostname).'.'.$zone;

        if (! Domain::query()->where('preview_hostname', $preview)->orWhere('hostname', $preview)->exists()) {
            return $preview;
        }

        return self::label($hostname, tail: bin2hex(random_bytes(3))).'.'.$zone;
    }

    /**
     * Get the preview hostname a hostname folds into, whether or not another domain holds it, or null
     * when previews are off.
     */
    public static function derive(string $hostname): ?string
    {
        $zone = self::zone();

        return $zone === null ? null : self::label($hostname).'.'.$zone;
    }

    /**
     * Determine if the hostname is under the preview zone (never, when previews are off).
     */
    public static function isPreview(string $hostname): bool
    {
        $zone = self::zone();

        if ($zone === null) {
            return false;
        }

        $hostname = strtolower($hostname);

        return $hostname === $zone || str_ends_with($hostname, '.'.$zone);
    }

    /**
     * Fold the hostname into one valid label: dots become dashes, and a name too long for a label
     * keeps its start plus a tail (a hash of the hostname, unless one is given).
     */
    private static function label(string $hostname, ?string $tail = null): string
    {
        $label = str_replace('.', '-', strtolower($hostname));

        // Dashes in the third and fourth places are reserved (xn--), and certificate authorities refuse them.
        if (substr($label, 2, 2) === '--') {
            $label = 'p-'.$label;
        }

        if ($tail === null && strlen($label) > self::MAX_LABEL_LENGTH) {
            $tail = substr(md5($hostname), 0, 8);
        }

        if ($tail === null) {
            return $label;
        }

        return rtrim(substr($label, 0, self::MAX_LABEL_LENGTH - strlen($tail) - 1), '-').'-'.$tail;
    }
}
