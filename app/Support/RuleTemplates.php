<?php

namespace App\Support;

use App\Addons\AddonSwitchboard;
use Illuminate\Contracts\Container\Container;

/**
 * The starter rules in the Rules page's template gallery: the core's, then each source's tagged in
 * under TAG (App\Support\RuleTemplateSource), in tag order.
 */
class RuleTemplates
{
    /**
     * The container tag sources register under.
     */
    public const string TAG = 'rules.templates';

    /**
     * The core's starter rules.
     *
     * @var array<string, array{name: string, blurb: string, source: string}>
     */
    private const array CORE = [
        'probe-scanners' => [
            'name' => 'Probe scanners',
            'blurb' => 'Anyone fishing for secrets, admin panels or exploits.',
            'source' => "-- Anyone fishing for secrets, admin panels or exploits.\nBAN ip, ja4 FOR 30 DAYS\nWHERE category = 'probe'",
        ],
        'multi-host-404' => [
            'name' => 'Multi-host 404 sweep',
            'blurb' => 'One IP collecting 404s across several of your sites.',
            'source' => "-- One IP collecting 404s across several of your sites.\nBAN ip FOR 1 HOUR\nWHERE status = 404\nWITHIN 10 MINUTES\nHAVING COUNT(*) >= 2 AND COUNT(DISTINCT host) >= 2",
        ],
        'ua-tls-mismatch' => [
            'name' => 'Browser name, script TLS',
            'blurb' => 'Claims to be Chrome or Firefox, but its TLS offers no ALPN.',
            'source' => "-- Claims to be Chrome or Firefox, but its TLS offers no ALPN: a script.\nTAG ip, ja4 AS 'ua-tls-mismatch' FOR 1 DAY\nWHERE (ua LIKE '%Chrome/%' OR ua LIKE '%Firefox/%')\n  AND ja4.alpn = '00'",
        ],
        'hosting-burst' => [
            'name' => 'Datacenter burst',
            'blurb' => 'A flood of requests from a cloud network in minutes.',
            'source' => "-- A flood of requests from a cloud or hosting network.\nBAN ip, ja4 FOR 6 HOURS\nWHERE network.kind = 'hosting'\nWITHIN 5 MINUTES\nHAVING COUNT(*) >= 60",
        ],
    ];

    public function __construct(private Container $container)
    {
        //
    }

    /**
     * Every template, keyed by slug: the core's, then each source's. A key already taken keeps the
     * template that had it first.
     *
     * @return array<string, array{name: string, blurb: string, source: string}>
     */
    public function all(): array
    {
        $templates = self::CORE;

        foreach (app(AddonSwitchboard::class)->active($this->container->tagged(self::TAG)) as $source) {
            if ($source instanceof RuleTemplateSource) {
                $templates += $source->ruleTemplates();
            }
        }

        return $templates;
    }

    /**
     * Find a template by its slug.
     *
     * @return array{name: string, blurb: string, source: string}|null
     */
    public function find(?string $key): ?array
    {
        return $key === null ? null : $this->all()[$key] ?? null;
    }
}
