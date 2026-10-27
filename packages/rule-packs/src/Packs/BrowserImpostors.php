<?php

namespace NiceTry\RulePacks\Packs;

use App\Enums\GymExit;
use NiceTry\RulePacks\RulePack;

/**
 * Clients whose parts disagree, or that run a browser no one has used for months: the core's `client`
 * field (App\Enums\ClientTrait), which already leaves out corporate security gateways for spoofing.
 * Real people sit behind some of them, so the routines are waiting rooms rather than walls.
 */
class BrowserImpostors extends RulePack
{
    public function key(): string
    {
        return 'browser-impostors';
    }

    public function version(): int
    {
        return 1;
    }

    public function name(): string
    {
        return 'Spoofed and outdated browsers';
    }

    public function description(): string
    {
        return 'Clients whose parts disagree: a browser’s name on a script’s TLS, an iPhone with Chrome’s handshake, Chrome without its client hints, headless Chrome, or a browser far behind the current release. Real people sit behind a few, so the routines are waiting rooms, not walls.';
    }

    public function icon(): string
    {
        return 'window';
    }

    public function rules(): array
    {
        return [
            'spoofed-browser' => [
                'name' => 'Spoofed browser',
                'blurb' => 'A client whose user agent, TLS and client hints tell different stories.',
                'source' => <<<'RULE'
                    -- A client whose user agent, TLS and client hints tell different stories.
                    TAG ip, ja4 AS 'spoofed-browser' FOR 1 DAY
                    WHERE client = 'spoofed'
                    RULE,
            ],
            'headless-browser' => [
                'name' => 'Headless browser',
                'blurb' => 'Headless Chrome or PhantomJS, by user agent or client hints: a browser no one is looking at.',
                'source' => <<<'RULE'
                    -- Headless Chrome or PhantomJS: a browser no one is looking at.
                    TAG ip, ja4 AS 'headless-browser' FOR 1 DAY
                    WHERE ua LIKE '%HeadlessChrome%'
                       OR hints LIKE '%HeadlessChrome%'
                       OR ua LIKE '%PhantomJS%'
                    RULE,
            ],
            'outdated-browser' => [
                'name' => 'Outdated browser',
                'blurb' => 'A browser further behind than people on its platform get, outside corporate gateways.',
                'source' => <<<'RULE'
                    -- A browser further behind than people on its platform get.
                    -- Corporate security gateways rewrite traffic, so they're left out.
                    TAG ip, ja4 AS 'outdated-browser' FOR 1 DAY
                    WHERE client = 'outdated'
                      AND client != 'corporate'
                    RULE,
            ],
        ];
    }

    public function routines(): array
    {
        return [
            'impostors-wait' => [
                'name' => 'Spoofed browsers wait',
                'blurb' => 'Spoofed and headless clients wait 20 seconds, then a browser check; a code if they still look automated.',
                'icon' => 'clock',
                'rules' => ['spoofed-browser', 'headless-browser'],
                'match_all' => true,
                'conditions' => [],
                'obstacles' => [
                    ['type' => 'waiting-room', 'config' => ['seconds' => 20, 'intensity' => 'steady', 'show_time' => true], 'escalate' => null],
                    ['type' => 'browser-check', 'config' => ['depth' => 'thorough'], 'escalate' => 'screen-code'],
                ],
                'exit' => GymExit::Continue,
                'pass_seconds' => 3600,
            ],
            'outdated-wait' => [
                'name' => 'Outdated browsers wait',
                'blurb' => 'Outdated browsers wait 15 seconds, once a day.',
                'icon' => 'clock',
                'rules' => ['outdated-browser'],
                'match_all' => true,
                'conditions' => [],
                'obstacles' => [
                    ['type' => 'waiting-room', 'config' => ['seconds' => 15, 'intensity' => 'light', 'show_time' => true], 'escalate' => null],
                ],
                'exit' => GymExit::Continue,
                'pass_seconds' => 86400,
            ],
        ];
    }
}
