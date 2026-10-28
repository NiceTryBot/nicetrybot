<?php

namespace NiceTry\Tarpit\Rules;

use App\Support\RuleTemplateSource;

/**
 * Starter rules meant for a tarpit: send them to one from the rule's page.
 */
class TarpitTemplates implements RuleTemplateSource
{
    public function ruleTemplates(): array
    {
        return [
            'tarpit-probers' => [
                'name' => 'Probers, for a tarpit',
                'blurb' => 'Tags anyone fishing for admin panels, secrets and exploits. On its own it only tags: save it, then send its clients to a tarpit from the rule\'s page, and every probe waits from the first one.',
                'source' => <<<'RULE'
                    TAG ip AS 'prober' FOR 1 DAY
                    WHERE category = 'probe'
                    RULE,
            ],
            'tarpit-404-walkers' => [
                'name' => '404 walkers, for a tarpit',
                'blurb' => 'Tags clients that collect 404s across your sites: wordlists, not people. On its own it only tags: save it, then send its clients to a tarpit from the rule\'s page.',
                'source' => <<<'RULE'
                    TAG ip AS '404-walker' FOR 1 DAY
                    WHERE status = 404
                    WITHIN 10 MINUTES
                    HAVING COUNT(*) >= 10 AND COUNT(DISTINCT host) >= 2
                    RULE,
            ],
        ];
    }
}
