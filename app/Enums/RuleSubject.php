<?php

namespace App\Enums;

/**
 * What a rule bans or tags: a client (its IP, its TLS fingerprint, or both together), or a scope (a
 * site, a path, or one path on one site). The subjects of a rule are also how its matching requests are
 * grouped, as in SQL's GROUP BY. A rule's subjects are all of one kind: a client's verdict follows the
 * client, a scope's verdict covers everyone on that site or path while it lasts (Swarm Mode, say).
 */
enum RuleSubject: string
{
    case Ip = 'ip';
    case Ja4 = 'ja4';
    case Host = 'host';
    case Path = 'path';

    /**
     * Get the edge_requests column the subject groups by.
     */
    public function column(): string
    {
        return match ($this) {
            self::Ip => 'edge_requests.client_ip',
            self::Ja4 => 'edge_requests.ja4_id',
            self::Host => 'edge_requests.domain_id',
            self::Path => 'edge_requests.path_id',
        };
    }

    /**
     * Determine if the subject is a scope (a site or a path) rather than a client.
     */
    public function isScope(): bool
    {
        return in_array($this, [self::Host, self::Path], true);
    }

    /**
     * Get the display label for the subject.
     */
    public function label(): string
    {
        return match ($this) {
            self::Ip => __('IP'),
            self::Ja4 => __('TLS fingerprint'),
            self::Host => __('site'),
            self::Path => __('path'),
        };
    }
}
