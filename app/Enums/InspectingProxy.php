<?php

namespace App\Enums;

/**
 * A secure web gateway that decrypts and re-encrypts its users' HTTPS, so the edge sees the gateway's
 * TLS handshake instead of the browser's. Recognised by the ASNs its proxies egress from, so a person
 * behind a corporate proxy isn't mistaken for a script wearing a browser's user agent.
 */
enum InspectingProxy: string
{
    case Iboss = 'iboss';
    case Zscaler = 'zscaler';
    case Netskope = 'netskope';
    case CiscoUmbrella = 'cisco-umbrella';

    /**
     * Find the gateway that announces an autonomous system, if it's a known one.
     */
    public static function forAsn(?int $asn): ?self
    {
        foreach (self::cases() as $proxy) {
            if (in_array($asn, $proxy->asns(), true)) {
                return $proxy;
            }
        }

        return null;
    }

    /**
     * Get the autonomous system numbers the gateway announces.
     *
     * @return list<int>
     */
    public function asns(): array
    {
        return match ($this) {
            self::Iboss => [396253],
            self::Zscaler => [22616],
            self::Netskope => [55256],
            self::CiscoUmbrella => [36692],
        };
    }

    /**
     * Get every known gateway's ASNs.
     *
     * @return list<int>
     */
    public static function allAsns(): array
    {
        return array_merge(...array_map(fn (self $proxy) => $proxy->asns(), self::cases()));
    }
}
