<?php

namespace NiceTry\Lockdown\Support;

/**
 * The link that lets an admin's browser through a lockdown on one hostname. The edge checks it with the
 * lockdown's secret (nicetry_lockdown in the edge repo signs and checks the same messages) and swaps it
 * for a pass cookie that lasts until the pass expires.
 */
final class LockdownPass
{
    /**
     * The path the edge answers the link on, under the gym's prefix so no site's own page is shadowed.
     */
    public const string PATH = '/_q/pass';

    /**
     * Get the link for a hostname: it must be opened before the link expires, and the pass lasts until
     * the pass expires (at most seven days later).
     */
    public static function url(string $secret, string $hostname, int $linkExpiresAt, int $passExpiresAt): string
    {
        return 'https://'.$hostname.self::PATH.'?t='.self::token($secret, $hostname, $linkExpiresAt, $passExpiresAt);
    }

    /**
     * Get the signed token the link carries: "<link expiry>.<pass expiry>.<hex HMAC-SHA256>".
     */
    public static function token(string $secret, string $hostname, int $linkExpiresAt, int $passExpiresAt): string
    {
        $signature = hash_hmac('sha256', "link\n".strtolower($hostname)."\n{$linkExpiresAt}\n{$passExpiresAt}", $secret);

        return "{$linkExpiresAt}.{$passExpiresAt}.{$signature}";
    }
}
