<?php

namespace App\Enums;

/**
 * What kind of network an autonomous system is. Free IP databases don't say, so hosting
 * providers are recognised from a curated list of their ASNs (see HostingProvider); everything
 * else is Unknown.
 */
enum NetworkKind: int
{
    case Unknown = 0;
    case Hosting = 1;

    /**
     * Classify an autonomous system by its number.
     */
    public static function forAsn(int $asn): self
    {
        return HostingProvider::forAsn($asn) === null ? self::Unknown : self::Hosting;
    }

    /**
     * Get the display label for the kind.
     */
    public function label(): string
    {
        return match ($this) {
            self::Hosting => __('Hosting'),
            self::Unknown => __('Network'),
        };
    }
}
