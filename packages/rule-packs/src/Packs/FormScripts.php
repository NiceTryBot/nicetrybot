<?php

namespace NiceTry\RulePacks\Packs;

use App\Enums\GymExit;
use NiceTry\RulePacks\RulePack;

/**
 * HTTP libraries and command-line tools on the paths where only people should be: sign-in, sign-up,
 * password and contact forms. Sign-ins that fail need the response, which only the request-monitor
 * add-on's captures give rules (its `response` field); without it that rule isn't offered.
 */
class FormScripts extends RulePack
{
    /**
     * What a form path contains, for the routine's entry.
     *
     * @var list<string>
     */
    private const array FORM_PATHS = ['login', 'signin', 'sign-in', 'sign_in', 'signup', 'sign-up', 'register', 'password', 'contact'];

    /**
     * What a script's user agent contains, for the routine's entry: HTTP libraries and command-line
     * tools, not the ones phone apps are built on.
     *
     * @var list<string>
     */
    private const array SCRIPT_AGENTS = ['curl/', 'wget/', 'python-requests', 'python-urllib', 'python-httpx', 'aiohttp', 'go-http-client', 'java/', 'apache-httpclient', 'libwww-perl', 'scrapy', 'node-fetch', 'axios/'];

    public function key(): string
    {
        return 'form-scripts';
    }

    public function version(): int
    {
        return 1;
    }

    public function name(): string
    {
        return 'Scripts on sign-in and forms';
    }

    public function description(): string
    {
        return 'curl, python-requests, Go’s HTTP client and friends on your sign-in, sign-up, password and contact forms, where only people should be, and clients hammering a sign-in. If your own apps or integrations post to those paths, leave the routines off. With the Request monitor add-on capturing your sign-in route, failed sign-ins too.';
    }

    public function icon(): string
    {
        return 'command-line';
    }

    public function rules(): array
    {
        return [
            'scripts-posting-forms' => [
                'name' => 'Scripts posting forms',
                'blurb' => 'HTTP libraries and command-line tools posting to sign-in, sign-up, password and contact forms.',
                'source' => <<<'RULE'
                    -- HTTP libraries and command-line tools posting to sign-in, sign-up, password and contact forms.
                    TAG ip, ja4 AS 'script-on-form' FOR 1 DAY
                    WHERE method = 'POST'
                      AND (ua IS NULL
                        OR ua LIKE 'curl/%' OR ua LIKE 'wget/%'
                        OR ua LIKE 'python-requests/%' OR ua LIKE 'python-urllib/%' OR ua LIKE '%python-httpx/%' OR ua LIKE '%aiohttp/%'
                        OR ua LIKE 'go-http-client/%' OR ua LIKE 'java/%' OR ua LIKE 'apache-httpclient/%'
                        OR ua LIKE 'libwww-perl/%' OR ua LIKE '%scrapy/%' OR ua LIKE 'node-fetch%' OR ua LIKE 'axios/%')
                      AND (path LIKE '%login%' OR path LIKE '%signin%' OR path LIKE '%sign_in%'
                        OR path LIKE '%signup%' OR path LIKE '%sign_up%' OR path LIKE '%register%'
                        OR path LIKE '%password%' OR path LIKE '%contact%')
                    RULE,
            ],
            'sign-in-hammering' => [
                'name' => 'Sign-in hammering',
                'blurb' => 'One client posting a sign-in form ten times in ten minutes.',
                'source' => <<<'RULE'
                    -- One client posting a sign-in form ten times in ten minutes.
                    TAG ip, ja4 AS 'sign-in-hammer' FOR 1 DAY
                    WHERE method = 'POST'
                      AND (path LIKE '%login%' OR path LIKE '%signin%' OR path LIKE '%sign_in%')
                    WITHIN 10 MINUTES
                    HAVING COUNT(*) >= 10
                    RULE,
            ],
            'failed-sign-ins' => [
                'name' => 'Failed sign-ins',
                'blurb' => 'One client told its password is wrong three times in ten minutes. Needs Request monitor capturing your sign-in route.',
                'source' => <<<'RULE'
                    -- One client told its password is wrong three times in ten minutes, in the words
                    -- popular frameworks use. Needs the Request monitor add-on capturing your sign-in route.
                    TAG ip, ja4 AS 'failed-sign-in' FOR 1 DAY
                    WHERE method = 'POST'
                      AND (response LIKE '%credentials do not match%'
                        OR response LIKE '%invalid email or password%'
                        OR response LIKE '%invalid username or password%'
                        OR response LIKE '%incorrect username or password%'
                        OR response LIKE '%incorrect email or password%'
                        OR response LIKE '%correct username and password%')
                    WITHIN 10 MINUTES
                    HAVING COUNT(*) >= 3
                    RULE,
            ],
        ];
    }

    public function routines(): array
    {
        return [
            'scripts-on-forms' => [
                'name' => 'Scripts on sign-in and forms',
                'blurb' => 'HTTP libraries and command-line tools on sign-in, sign-up, password and contact pages solve a proof-of-work puzzle, which needs a browser.',
                'icon' => 'command-line',
                'rules' => [],
                'match_all' => true,
                'conditions' => [
                    ['field' => 'ua', 'operator' => 'contains', 'values' => self::SCRIPT_AGENTS],
                    ['field' => 'path', 'operator' => 'contains', 'values' => self::FORM_PATHS],
                ],
                'obstacles' => [
                    ['type' => 'proof-of-work', 'config' => ['difficulty' => 18, 'display' => 'progress'], 'escalate' => null],
                ],
                'exit' => GymExit::Continue,
                'pass_seconds' => 3600,
            ],
            'hammering-waits' => [
                'name' => 'Sign-in hammering waits',
                'blurb' => 'Clients hammering or failing a sign-in wait 30 seconds, then a browser check; a code if they look automated.',
                'icon' => 'clock',
                'rules' => ['sign-in-hammering', 'failed-sign-ins'],
                'match_all' => true,
                'conditions' => [],
                'obstacles' => [
                    ['type' => 'waiting-room', 'config' => ['seconds' => 30, 'intensity' => 'steady', 'show_time' => true], 'escalate' => null],
                    ['type' => 'browser-check', 'config' => ['depth' => 'thorough'], 'escalate' => 'screen-code'],
                ],
                'exit' => GymExit::Continue,
                'pass_seconds' => 3600,
            ],
        ];
    }
}
