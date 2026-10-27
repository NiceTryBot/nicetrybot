<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;

/**
 * An IP range written as CIDR ("198.51.100.0/24"), for matching an IP column against it.
 */
final class IpRange
{
    /**
     * Determine if a value is a CIDR range: an IPv4 or IPv6 address, a slash and a prefix length it allows.
     */
    public static function isRange(string $value): bool
    {
        [$address, $bits] = array_pad(explode('/', $value, 2), 2, '');

        if (! ctype_digit($bits)) {
            return false;
        }

        $maxBits = match (true) {
            filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false => 32,
            filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false => 128,
            default => null,
        };

        return $maxBits !== null && (int) $bits <= $maxBits;
    }

    /**
     * Keep the rows whose IP column is in the range. PostgreSQL compares inet values; other databases store
     * the IP as text, so only whole-octet IPv4 ranges (/8, /16, /24, /32) can be matched there.
     */
    public static function constrain(Builder $query, string $column, string $range, string $boolean = 'and'): void
    {
        if ($query->getGrammar() instanceof PostgresGrammar) {
            $query->whereRaw("{$column} <<= cast(? as inet)", [$range], $boolean);

            return;
        }

        [$address, $bits] = explode('/', $range, 2);
        $octets = intdiv((int) $bits, 8);

        match (true) {
            (int) $bits === 0 => $query->whereRaw('1 = 1', boolean: $boolean),
            filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || (int) $bits % 8 !== 0 => $query->whereRaw('1 = 0', boolean: $boolean),
            $octets === 4 => $query->where($column, '=', $address, $boolean),
            default => $query->where($column, 'like', implode('.', array_slice(explode('.', $address), 0, $octets)).'.%', $boolean),
        };
    }
}
