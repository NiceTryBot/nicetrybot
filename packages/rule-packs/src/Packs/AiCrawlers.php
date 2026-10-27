<?php

namespace NiceTry\RulePacks\Packs;

use NiceTry\RulePacks\RulePack;

/**
 * The AI companies' crawlers and assistants, by the names they give, and impostors wearing those names
 * to probe. Tags only: what to do with AI traffic is the team's call.
 */
class AiCrawlers extends RulePack
{
    public function key(): string
    {
        return 'ai-crawlers';
    }

    public function version(): int
    {
        return 1;
    }

    public function name(): string
    {
        return 'AI crawlers';
    }

    public function description(): string
    {
        return 'Names the AI companies’ crawlers and assistants as they arrive, and catches impostors wearing their names to probe your sites. Tags only, so you can see how much AI traffic you get before deciding what to do with it.';
    }

    public function icon(): string
    {
        return 'cpu-chip';
    }

    public function rules(): array
    {
        return [
            'ai-training' => [
                'name' => 'AI training crawlers',
                'blurb' => 'Crawlers gathering pages to train AI models, by the names they give.',
                'source' => <<<'RULE'
                    -- Crawlers gathering pages to train AI models, by the names they give.
                    TAG ip AS 'ai-crawler' FOR 1 DAY
                    WHERE ua LIKE '%GPTBot%'
                       OR ua LIKE '%ClaudeBot%'
                       OR ua LIKE '%anthropic-ai%'
                       OR ua LIKE '%CCBot%'
                       OR ua LIKE '%Bytespider%'
                       OR ua LIKE '%meta-externalagent%'
                       OR ua LIKE '%Amazonbot%'
                       OR ua LIKE '%Google-CloudVertexBot%'
                       OR ua LIKE '%cohere-training-data-crawler%'
                       OR ua LIKE '%Diffbot%'
                       OR ua LIKE '%AI2Bot%'
                       OR ua LIKE '%PanguBot%'
                       OR ua LIKE '%Timpibot%'
                       OR ua LIKE '%ImagesiftBot%'
                    RULE,
            ],
            'ai-assistants' => [
                'name' => 'AI assistants and answer engines',
                'blurb' => 'AI search and assistants fetching a page because someone asked about it.',
                'source' => <<<'RULE'
                    -- AI search and assistants fetching a page because someone asked about it.
                    TAG ip AS 'ai-assistant' FOR 1 DAY
                    WHERE ua LIKE '%ChatGPT-User%'
                       OR ua LIKE '%OAI-SearchBot%'
                       OR ua LIKE '%Claude-User%'
                       OR ua LIKE '%Claude-SearchBot%'
                       OR ua LIKE '%Claude-Web%'
                       OR ua LIKE '%PerplexityBot%'
                       OR ua LIKE '%Perplexity-User%'
                       OR ua LIKE '%DuckAssistBot%'
                       OR ua LIKE '%MistralAI-User%'
                       OR ua LIKE '%meta-externalfetcher%'
                       OR ua LIKE '%cohere-ai%'
                       OR ua LIKE '%YouBot%'
                    RULE,
            ],
            'ai-impostors' => [
                'name' => 'AI crawler names, probing',
                'blurb' => 'An AI crawler’s name on a client fishing for secrets and exploits. Real crawlers follow links; they don’t walk wordlists.',
                'source' => <<<'RULE'
                    -- An AI crawler's name on a client fishing for secrets and exploits.
                    -- Real crawlers follow links, so they rarely miss three probe paths in an hour.
                    TAG ip, ja4 AS 'fake-ai-crawler' FOR 7 DAYS
                    WHERE (ua LIKE '%GPTBot%' OR ua LIKE '%ChatGPT-User%' OR ua LIKE '%OAI-SearchBot%'
                        OR ua LIKE '%ClaudeBot%' OR ua LIKE '%Claude-%' OR ua LIKE '%anthropic-ai%'
                        OR ua LIKE '%PerplexityBot%' OR ua LIKE '%Perplexity-User%' OR ua LIKE '%cohere%'
                        OR ua LIKE '%CCBot%' OR ua LIKE '%Bytespider%' OR ua LIKE '%meta-external%')
                      AND category = 'probe'
                      AND status = 404
                    WITHIN 1 HOUR
                    HAVING COUNT(DISTINCT path) >= 3
                    RULE,
            ],
        ];
    }
}
