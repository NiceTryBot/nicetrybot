<?php

namespace NiceTry\Tarpit\Rules;

use App\Support\RuleTemplateSource;

/**
 * Starter rules that send their clients to a tarpit, making it by name if the team doesn't have it yet.
 * The rule says who goes in; the tarpit says how long they wait. Add GYM 'routine' to one as well and a
 * client that comes back after the wait has to clear that routine (a captcha, say).
 */
class TarpitTemplates implements RuleTemplateSource
{
    /**
     * Paths no real visitor asks for on most sites: secrets, source control, server and editor leftovers.
     * Matched anywhere in the path, ignoring case, so each is specific enough not to turn up in a page's
     * own path (a bare "/credentials" would catch a page about nursing credentials).
     */
    public const array PROBE_PATHS = [
        '/.env', '/.git/', '/.aws/', '/.ssh/', '/id_rsa', '/.htpasswd', '/.npmrc', '/.docker/', '/.git-credentials',
        'wp-config.php', 'phpinfo', '/vendor/phpunit/', '/.ds_store', '/.vscode/', '/.svn/', '/server-status',
        '/actuator', '/phpmyadmin', '/config.json', '.sql',
    ];

    public function ruleTemplates(): array
    {
        $probes = implode("\n   OR ", array_map(fn (string $path) => "path LIKE '%{$path}%'", self::PROBE_PATHS));

        return [
            'tarpit-http-status' => [
                'name' => 'Error answers, to a tarpit',
                'blurb' => 'Holds the origin\'s 403, 404, 429 and 500 answers in the "tarpit-http-status" tarpit, then every request from those clients for 5 minutes. Static assets are let through.',
                'source' => <<<'RULE'
                    TAG ip, ja4 AS 'tarpit-http-status' FOR 5 MINUTES
                    TARPIT 'tarpit-http-status'
                    WHERE status IN (403, 404, 429, 500)
                      AND action = 'passed'
                    RULE,
            ],
            'tarpit-probe-path' => [
                'name' => 'Probe paths, to a tarpit',
                'blurb' => 'Holds a request for a path no real visitor asks for (.env, .git/, phpinfo…) in the "tarpit-probe-path" tarpit before your origin sees it, then every request from that client for 5 minutes.',
                'source' => "TAG ip, ja4 AS 'tarpit-probe-path' FOR 5 MINUTES\nTARPIT 'tarpit-probe-path'\nWHERE {$probes}",
            ],
        ];
    }
}
