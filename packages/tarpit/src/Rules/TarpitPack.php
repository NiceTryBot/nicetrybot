<?php

namespace NiceTry\Tarpit\Rules;

use NiceTry\RulePacks\RulePack;

/**
 * The tarpit's rule pack, offered when the Rule packs add-on is installed: two rules the edge acts on
 * as each request happens, with no wait for the minute's run and no client singled out first.
 *
 * Error answers is status-only (StatusHolds), so the edge holds the origin's error answer itself, each
 * client climbing the tarpit's ladder from its first free try: a scanner piling up 404s soon waits the
 * cap on every one, while a visitor with a broken link waits for nothing. Its verdict lasts a minute
 * from the client's last error, so it has lapsed by the time the minute's run lands, and the holds stay
 * the edge's alone. Without Tarpit Pro only the 404 is held.
 *
 * Probe paths is decided by one request, so the edge holds that request before the origin sees it, and
 * the hour's verdict then holds the rest of what that client sends.
 */
class TarpitPack extends RulePack
{
    public function key(): string
    {
        return 'tarpit';
    }

    public function version(): int
    {
        return 2;
    }

    public function name(): string
    {
        return 'Tarpit traps';
    }

    public function description(): string
    {
        return 'Make scanners wait at the edge the moment they show themselves: your sites’ error answers are held where they happen, longer for each one a client piles up, and a request for a path no visitor asks for (.env, .git/, phpinfo…) is held before your site sees it. No client has to be picked out first. Without Tarpit Pro only 404s are held. They act at once, so they install switched off.';
    }

    public function icon(): string
    {
        return 'clock';
    }

    public function rules(): array
    {
        $probes = implode("\n   OR ", array_map(fn (string $path) => "path LIKE '%{$path}%'", TarpitTemplates::PROBE_PATHS));

        return [
            'error-answers' => [
                'name' => 'Error answers wait',
                'blurb' => 'Your sites’ 401, 403, 404, 405, 429 and 500 answers are held at the edge, longer for each one a client piles up. 404 only without Tarpit Pro.',
                'source' => <<<'RULE'
                    -- Your sites' error answers, held at the edge as they happen: each client gets a free
                    -- try, then waits longer for every one after. Without Tarpit Pro only 404s are held.
                    -- The verdict lapses a minute after the client's last error, so the holds stay the edge's.
                    -- Only your origin's answers count, never the gym's or the Bouncer's own 403s.
                    TAG ip, ja4 AS 'error-answer' FOR 1 MINUTE
                    TARPIT 'Error answers'
                    WHERE status IN (401, 403, 404, 405, 429, 500)
                      AND action = 'passed'
                    RULE,
            ],
            'probe-paths' => [
                'name' => 'Probe paths wait',
                'blurb' => 'A request for a path no visitor asks for (.env, .git/, phpinfo…) is held before your site sees it, then everything that client sends for an hour.',
                'source' => "-- A path no visitor asks for: the edge holds the request before your site sees it,\n-- then everything that client sends for an hour.\nTAG ip, ja4 AS 'probe-path' FOR 1 HOUR\nTARPIT 'Probe paths'\nWHERE {$probes}",
            ],
        ];
    }
}
