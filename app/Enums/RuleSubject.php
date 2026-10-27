<?php

namespace App\Enums;

/**
 * What a rule bans or tags: the client IP, its TLS fingerprint, or both together. The subjects of a
 * rule are also how its matching requests are grouped, as in SQL's GROUP BY.
 */
enum RuleSubject: string
{
    case Ip = 'ip';
    case Ja4 = 'ja4';

    /**
     * Get the edge_requests column the subject groups by.
     */
    public function column(): string
    {
        return match ($this) {
            self::Ip => 'edge_requests.client_ip',
            self::Ja4 => 'edge_requests.ja4_id',
        };
    }

    /**
     * Get the display label for the subject.
     */
    public function label(): string
    {
        return match ($this) {
            self::Ip => __('IP'),
            self::Ja4 => __('TLS fingerprint'),
        };
    }
}
