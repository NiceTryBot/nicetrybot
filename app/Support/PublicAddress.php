<?php

namespace App\Support;

/**
 * Whether an IP address is on the public internet: not private, loopback, link-local (a cloud's metadata
 * service at 169.254.169.254), reserved, carrier-grade NAT, benchmarking or multicast, and not one of
 * those written as IPv6.
 */
final class PublicAddress
{
    public static function isPublic(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = (int) ip2long($address);

            // Carrier-grade NAT (100.64.0.0/10), benchmarking (198.18.0.0/15) and multicast (224.0.0.0/4),
            // which PHP's flags let through.
            return ! (($long & 0xFFC00000) === (int) ip2long('100.64.0.0')
                || ($long & 0xFFFE0000) === (int) ip2long('198.18.0.0')
                || ($long & 0xF0000000) === (int) ip2long('224.0.0.0'));
        }

        // IPv6: multicast, and IPv4 addresses written as IPv6, checked as IPv4.
        $packed = (string) inet_pton($address);

        if ($packed[0] === "\xFF") {
            return false;
        }

        if (str_starts_with($packed, str_repeat("\0", 10)."\xFF\xFF")) {
            return self::isPublic((string) inet_ntop(substr($packed, 12)));
        }

        return true;
    }
}
