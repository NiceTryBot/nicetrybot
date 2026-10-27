<?php

namespace NiceTry\RulePacks\Packs;

use App\Enums\GymExit;
use NiceTry\RulePacks\RulePack;

/**
 * Vulnerability scanners walking wordlists, told apart by the path categories (App\Enums\PathCategory)
 * and by asking for what isn't there: a WordPress site answers wp-login.php, so only a miss counts.
 */
class Scanners extends RulePack
{
    public function key(): string
    {
        return 'scanners';
    }

    public function version(): int
    {
        return 1;
    }

    public function name(): string
    {
        return 'Scanners and 404 probes';
    }

    public function description(): string
    {
        return 'Vulnerability scanners working down wordlists of secrets, source files, admin panels and CMS paths. Only requests your sites answer with “not here” count, so a real WordPress admin or a page that happens to match is never tagged. The routine makes tagged scanners work before every page.';
    }

    public function icon(): string
    {
        return 'document-magnifying-glass';
    }

    public function rules(): array
    {
        return [
            'secret-hunters' => [
                'name' => 'Secret and source hunters',
                'blurb' => 'Asking for .env files, keys, .git and backups that aren’t there.',
                'source' => <<<'RULE'
                    -- Asking for .env files, keys, .git and backups that aren't there:
                    -- nothing a visitor ever needs.
                    TAG ip, ja4 AS 'secret-hunter' FOR 7 DAYS
                    WHERE category IN ('probe/creds', 'probe/source')
                      AND status IN (403, 404)
                    RULE,
            ],
            'cms-probes' => [
                'name' => 'CMS and admin probes that miss',
                'blurb' => 'wp-login.php, xmlrpc.php, phpMyAdmin and friends on a site that has none.',
                'source' => <<<'RULE'
                    -- wp-login.php, xmlrpc.php, phpMyAdmin and friends on a site that has none.
                    -- A real WordPress site answers them, so only misses count.
                    TAG ip, ja4 AS 'cms-probe' FOR 1 DAY
                    WHERE category IN ('probe/cms', 'probe/admin')
                      AND status = 404
                    WITHIN 10 MINUTES
                    HAVING COUNT(DISTINCT path) >= 2
                    RULE,
            ],
            'wordlist-sweep' => [
                'name' => 'Wordlist sweep',
                'blurb' => 'Five different probe paths that aren’t there, in ten minutes.',
                'source' => <<<'RULE'
                    -- Five different probe paths that aren't there, in ten minutes:
                    -- a scanner working down its list.
                    TAG ip, ja4 AS 'scanner' FOR 1 DAY
                    WHERE category = 'probe'
                      AND status = 404
                    WITHIN 10 MINUTES
                    HAVING COUNT(DISTINCT path) >= 5
                    RULE,
            ],
            'multi-site-404s' => [
                'name' => '404s across your sites',
                'blurb' => 'One client missing pages on three or more of your sites in ten minutes.',
                'source' => <<<'RULE'
                    -- One client missing pages on three or more of your sites in ten minutes.
                    -- Icons and other assets browsers ask for on their own don't count.
                    TAG ip AS 'multi-site-404' FOR 1 DAY
                    WHERE status = 404
                      AND category != 'asset'
                    WITHIN 10 MINUTES
                    HAVING COUNT(*) >= 3 AND COUNT(DISTINCT host) >= 3
                    RULE,
            ],
        ];
    }

    public function routines(): array
    {
        return [
            'scanners-work' => [
                'name' => 'Scanners work for it',
                'blurb' => 'Clients these rules tagged solve a proof-of-work puzzle before each page, and wait in a queue if they look automated.',
                'icon' => 'document-magnifying-glass',
                'rules' => ['secret-hunters', 'cms-probes', 'wordlist-sweep', 'multi-site-404s'],
                'match_all' => true,
                'conditions' => [],
                'obstacles' => [
                    ['type' => 'proof-of-work', 'config' => ['difficulty' => 20, 'display' => 'quiet'], 'escalate' => 'waiting-room'],
                ],
                'exit' => GymExit::Continue,
                'pass_seconds' => 3600,
            ],
        ];
    }
}
