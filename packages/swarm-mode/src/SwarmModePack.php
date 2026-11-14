<?php

namespace NiceTry\SwarmMode;

use NiceTry\RulePacks\RulePack;

/**
 * The Swarm Mode pack: three rules that put a site or URL into Swarm Mode when 3 or more IPs get an
 * error there within a minute, and one that has the Bouncer check every visitor while it's on.
 *
 * Only the origin's own answers count (action = 'passed'), so the Bouncer's challenges and the tarpit's
 * 429s never keep a swarm going, and static assets never count. Plain 404s and 500s are left out: those
 * are dead links and a struggling app, not a swarm. A Livewire call comes from a page, so a swarm on it
 * puts the whole site in Swarm Mode, and the Bouncer then checks the pages a script would get its next
 * token from; an API call has no page, so a swarm on one puts just that URL in Swarm Mode.
 *
 * The swarm rules start switched on: they only turn the mode on. The Bouncer rule acts on real visitors
 * as soon as it's on, so it starts switched off.
 */
class SwarmModePack extends RulePack
{
    /**
     * The statuses a swarm counts: refused logins and forbidden paths (401, 403), method probes (405),
     * form posts with no session (419), and being rate-limited (429).
     */
    private const string STATUSES = '401, 403, 405, 419, 429';

    public function key(): string
    {
        return 'swarm-mode';
    }

    public function version(): int
    {
        return 1;
    }

    public function name(): string
    {
        return 'Swarm Mode';
    }

    public function description(): string
    {
        return 'Puts a URL into Swarm Mode for 5 minutes when 3 or more IPs get a 401, 403, 405, 419 or 429 from your origin there within a minute (a whole site, when it\'s the Livewire endpoint). While it\'s on, the Bouncer rule has every visitor tick one box once, and an API call gets a JSON answer naming the challenge to open in a browser. The swarm rules start on; the Bouncer rule starts off until you switch it on.';
    }

    public function icon(): string
    {
        return 'bolt';
    }

    public function rules(): array
    {
        $statuses = self::STATUSES;

        return [
            'swarm-pages' => [
                'name' => 'Swarm on a page',
                'blurb' => 'Puts one URL into Swarm Mode for 5 minutes when 3 or more IPs get an error from your origin there within a minute.',
                'source' => <<<RULE
                    -- 3 or more IPs getting an error from your origin on the same page within a minute:
                    -- put that URL into Swarm Mode for 5 minutes. Static assets, the API and Livewire have
                    -- rules of their own.
                    TAG host, path AS 'swarm-page' FOR 5 MINUTES
                    SWARM
                    WHERE status IN ({$statuses})
                      AND action = 'passed'
                      AND (category IS NULL OR category != 'asset')
                      AND path NOT LIKE '/api/%'
                      AND path NOT LIKE '/livewire%'
                    WITHIN 1 MINUTE
                    HAVING COUNT(DISTINCT ip) >= 3
                    RULE,
            ],
            'swarm-livewire' => [
                'name' => 'Swarm on Livewire',
                'blurb' => 'Puts the whole site into Swarm Mode for 5 minutes when 3 or more IPs get an error from its Livewire endpoint within a minute.',
                'source' => <<<RULE
                    -- 3 or more IPs getting an error from the site's Livewire endpoint within a minute: put
                    -- the whole site into Swarm Mode for 5 minutes. A Livewire call comes from a page, so
                    -- the Bouncer checks the pages a script would get its next token from.
                    TAG host AS 'swarm-livewire' FOR 5 MINUTES
                    SWARM
                    WHERE status IN ({$statuses})
                      AND action = 'passed'
                      AND (category IS NULL OR category != 'asset')
                      AND path LIKE '/livewire%'
                    WITHIN 1 MINUTE
                    HAVING COUNT(DISTINCT ip) >= 3
                    RULE,
            ],
            'swarm-api' => [
                'name' => 'Swarm on an API',
                'blurb' => 'Puts one API URL into Swarm Mode for 5 minutes when 3 or more IPs get an error from it within a minute.',
                'source' => <<<RULE
                    -- 3 or more IPs getting an error from the same API URL within a minute: put that URL
                    -- into Swarm Mode for 5 minutes.
                    TAG host, path AS 'swarm-api' FOR 5 MINUTES
                    SWARM
                    WHERE status IN ({$statuses})
                      AND action = 'passed'
                      AND path LIKE '/api/%'
                    WITHIN 1 MINUTE
                    HAVING COUNT(DISTINCT ip) >= 3
                    RULE,
            ],
            'swarm-bounce' => [
                'name' => 'Bounce while swarmed',
                'blurb' => 'While a site or URL is in Swarm Mode, every visitor ticks the Bouncer\'s box once. An API call gets a JSON answer naming the challenge.',
                'source' => <<<'RULE'
                    -- While a site or URL is in Swarm Mode, every visitor ticks the Bouncer's box once. A
                    -- page shows the checkbox; an API call gets a 429 in JSON naming the challenge to open
                    -- in a browser, and a script's IP is let through once someone clears it.
                    -- Needs the Bouncer add-on.
                    TAG ip, ja4 AS 'swarm-bounce' FOR 5 MINUTES
                    BOUNCER
                    WHERE swarm IS NOT NULL
                    RULE,
            ],
        ];
    }

    public function startsOn(string $rule): ?bool
    {
        return $rule !== 'swarm-bounce';
    }
}
