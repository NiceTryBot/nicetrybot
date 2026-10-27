<?php

namespace App\Concerns;

use App\Enums\HttpMethod;
use App\Enums\PathCategory;
use App\Enums\RequestAction;
use App\Support\RuleFieldDefinition;
use App\Support\RuleFields;
use Illuminate\Support\Arr;

/**
 * What Claude needs to write the rule language about a team's traffic: the fields and their values,
 * and the team's hostnames, trimmed for a team with more than fit.
 */
trait DescribesTrafficToClaude
{
    /**
     * The most hostnames sent along, so a large team's question stays small. Past it, the hostnames the
     * question names go first, and every parent domain is counted.
     */
    public const int MAX_DOMAINS = 200;

    /**
     * The most parent domains counted for a team with more hostnames than are sent.
     */
    private const int MAX_GROUPS = 40;

    /**
     * The fields a condition can test, and the values they take: the core's and every installed
     * add-on's.
     */
    protected function fieldReference(): string
    {
        $fields = collect(app(RuleFields::class)->all())->map(function (RuleFieldDefinition $field) {
            $kind = match (true) {
                $field->isNumeric() => 'number',
                $field->isText() => 'text',
                default => 'one of the values below',
            };

            return "- {$field->value}: {$field->englishName()} ({$kind})".($field->hint() !== '' ? ". {$field->hint()}" : '');
        })->implode("\n");

        $actions = collect(RequestAction::cases())->map(fn (RequestAction $action) => "'{$action->slug()}' ({$action->label()})")->implode(', ');
        $actionList = fn (array $actions) => implode(', ', array_map(fn (RequestAction $action) => "'{$action->slug()}'", $actions));
        $categories = collect(PathCategory::cases())
            ->map(fn (PathCategory $category) => "  - '{$category->slug()}': {$category->description()} Like ".implode(', ', array_slice($category->patterns(), 0, 8)).'.')
            ->implode("\n");
        $methods = collect(HttpMethod::cases())->reject(fn (HttpMethod $method) => $method === HttpMethod::Other)->map(fn (HttpMethod $method) => "'{$method->label()}'")->implode(', ');

        return <<<REFERENCE
            # Fields
            {$fields}

            # Values
            - action: {$actions}. "Suspicious" means action IN ({$actionList(RequestAction::suspicious())}); "kept off the origin" or "stopped" means action IN ({$actionList(RequestAction::keptOffOrigin())}).
            - category: a family ('probe', 'asset', 'edge') or one of these slugs, each with some of its paths (* matches anything). A request with no category is an ordinary page (category IS NULL).
            {$categories}
            - Categories group paths by what a probe is after, not by product, so one product's paths sit in several: WordPress's are in probe/admin (/wp-login.php, /wp-admin), probe/cms (/wp-content, /wp-includes, /xmlrpc.php) and probe/creds (/wp-config.php). For a product or technology ("WordPress", "PHP", "git", "Laravel"), match its paths with LIKE rather than one category.
            - method: {$methods}.
            - network.kind: 'hosting' for cloud and datacenter networks (AWS, Google Cloud, DigitalOcean, OVH…), 'unknown' for everything else, like home and mobile ISPs.
            - country: a two-letter ISO code in capitals, like 'US', 'CN', 'RU'.
            - client: 'spoofed' for clients that aren't the browser they claim to be (a user agent its TLS handshake or client hints contradict), 'outdated' for a Chrome or Firefox many versions behind the one people run now, 'corporate' for clients behind a corporate security gateway (Zscaler, Netskope, iboss, Cisco Umbrella). Only =, != and IN; a client can be several at once, so "spoofed and outdated" is client = 'spoofed' AND client = 'outdated'.
            - ip: one address, or a CIDR range like '66.249.64.0/19', with =, != or IN.
            - status: the HTTP status. A class like 4xx is status >= 400 AND status < 500; "errors" means status >= 400.
            - duration: milliseconds. "Slow" with no number means duration > 1000.
            REFERENCE;
    }

    /**
     * What the fields' notes say about writing rules with them, one line per note, naming every field
     * that has it, e.g. "- a and a.b: only on …". Empty when no field has a note.
     */
    protected function fieldNotes(): string
    {
        return collect(app(RuleFields::class)->all())
            ->filter(fn (RuleFieldDefinition $field) => $field->ruleNote() !== '')
            ->groupBy(fn (RuleFieldDefinition $field) => $field->ruleNote())
            ->map(fn ($fields, string $note) => '- '.Arr::join($fields->map(fn (RuleFieldDefinition $field) => (string) $field->value)->all(), ', ', ' and ').': '.$note)
            ->implode("\n");
    }

    /**
     * Pick the hostnames to send. A team with more than fit sends every one the question names (by any
     * part of it, like "settlement"), then the rest in order, and counts each parent domain.
     *
     * @param  list<string>  $domains
     * @return array{hostnames: list<string>, total: int, groups: array<string, int>}
     */
    protected function hostnames(string $question, array $domains): array
    {
        if (count($domains) <= self::MAX_DOMAINS) {
            return ['hostnames' => $domains, 'total' => count($domains), 'groups' => []];
        }

        $asked = mb_strtolower($question);
        $named = array_filter($domains, fn (string $hostname) => collect(explode('.', mb_strtolower($hostname)))
            ->slice(0, -1)
            ->contains(fn (string $label) => mb_strlen($label) >= 4 && $label !== 'www' && str_contains($asked, $label)));

        return [
            'hostnames' => array_slice(array_values(array_unique([...$named, ...$domains])), 0, self::MAX_DOMAINS),
            'total' => count($domains),
            'groups' => collect($domains)
                ->countBy(fn (string $hostname) => implode('.', array_slice(explode('.', $hostname), -2)))
                ->filter(fn (int $count) => $count > 1)
                ->sortDesc()
                ->take(self::MAX_GROUPS)
                ->all(),
        ];
    }

    /**
     * The hostnames as prompt parts: the list (saying how many there are when it's partial), then the
     * count under each parent domain.
     *
     * @param  array{hostnames: list<string>, total: int, groups: array<string, int>}  $hostnames
     * @return list<string>
     */
    protected function hostnameParts(array $hostnames): array
    {
        $parts = [];

        if ($hostnames['hostnames'] !== []) {
            $parts[] = $hostnames['total'] > count($hostnames['hostnames'])
                ? '<hostnames shown="'.count($hostnames['hostnames'])."\" total=\"{$hostnames['total']}\">\n".implode("\n", $hostnames['hostnames'])."\n</hostnames>"
                : "<hostnames>\n".implode("\n", $hostnames['hostnames'])."\n</hostnames>";
        }

        if ($hostnames['groups'] !== []) {
            $parts[] = "<hostnames_per_parent_domain>\n".collect($hostnames['groups'])->map(fn (int $count, string $parent) => "{$parent}: {$count}")->implode("\n")."\n</hostnames_per_parent_domain>";
        }

        return $parts;
    }
}
