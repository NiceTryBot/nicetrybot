<?php

namespace App\Enums;

use App\Data\BreakdownRow;

/**
 * A dimension of edge traffic the Analytics page can group by.
 */
enum TrafficBreakdown: string
{
    case Networks = 'networks';
    case Countries = 'countries';
    case UserAgents = 'user-agents';
    case Fingerprints = 'fingerprints';
    case Categories = 'categories';

    /**
     * Get the display label for the breakdown.
     */
    public function label(): string
    {
        return match ($this) {
            self::Networks => __('Networks'),
            self::Countries => __('Countries'),
            self::UserAgents => __('User agents'),
            self::Fingerprints => __('TLS fingerprints'),
            self::Categories => __('Path categories'),
        };
    }

    /**
     * Get a short explanation of what the breakdown answers.
     */
    public function description(): string
    {
        return match ($this) {
            self::Networks => __('Which ISPs and hosting providers the requests came from. Hosting traffic is rarely a person at a browser.'),
            self::Countries => __('Where client IPs geolocate to.'),
            self::UserAgents => __('The User-Agent header exactly as sent. One user agent across many TLS stacks is usually borrowed.'),
            self::Fingerprints => __('JA4 TLS client fingerprints: the software, not the person. One fingerprint behind many user agents is a script rotating names.'),
            self::Categories => __('Paths grouped by established patterns, e.g. every .env file is probe/creds. Each category is defined only by its patterns, so it can become an edge rule.'),
        };
    }

    /**
     * Get the heading for the count of related distinct values shown per row.
     */
    public function relatedLabel(): string
    {
        return match ($this) {
            self::Networks, self::UserAgents => __('TLS stacks'),
            self::Countries => __('Cities'),
            self::Fingerprints => __('User agents'),
            self::Categories => __('Paths'),
        };
    }

    /**
     * Get the Activity filter that shows a row's requests, or null when the row can't be filtered on.
     *
     * @return array<string, int|string>|null
     */
    public function activityFilter(BreakdownRow $row): ?array
    {
        if ($row->key === null) {
            return null;
        }

        return match ($this) {
            self::Networks => ['asn' => $row->key],
            self::Countries => ['country' => $row->key],
            self::UserAgents => ['ua' => $row->key],
            self::Fingerprints => ['ja4' => $row->label],
            self::Categories => ['category' => $row->key],
        };
    }
}
