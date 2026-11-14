<?php

namespace NiceTry\SwarmMode\Rules;

use App\Support\RuleFieldDefinition;
use NiceTry\SwarmMode\Models\ManualSwarm;

/**
 * The field Swarm Mode brings to rules and Activity searches: `swarm`, the tag of the Swarm Mode a
 * request fell under (its site's, its path's on every site, or that path's on that site; 'swarm-manual'
 * when someone turned it on by hand), or null. So
 * "swarm IS NOT NULL" is every request made while its site or URL was in Swarm Mode.
 *
 * The edge doesn't know it, so it isn't live: rules reading it are judged from the logs, except at the
 * Bouncer's door, which asks for it (SwarmDoorField).
 */
enum SwarmRuleField: string implements RuleFieldDefinition
{
    case Swarm = 'swarm';

    public function column(): string
    {
        $byRule = '(select sv.tag from scope_verdicts sv '
            .'where sv.team_id = domains.team_id '
            .'and sv.edge_rule_id in (select edge_rule_id from swarm_rules) '
            .'and sv.lifted_at is null '
            .'and (sv.domain_id is null or sv.domain_id = edge_requests.domain_id) '
            .'and (sv.path_id is null or sv.path_id = edge_requests.path_id) '
            .'and sv.first_matched_at <= edge_requests.occurred_at '
            .'and (sv.expires_at is null or sv.expires_at > edge_requests.occurred_at) '
            .'limit 1)';
        $byHand = "(select '".ManualSwarm::TAG."' from manual_swarms ms "
            .'where ms.team_id = domains.team_id '
            .'and ms.domain_id = edge_requests.domain_id '
            .'and (ms.path_id is null or ms.path_id = edge_requests.path_id) '
            .'and ms.started_at <= edge_requests.occurred_at '
            .'and coalesce(ms.ended_at, ms.ends_at) > edge_requests.occurred_at '
            .'limit 1)';

        return "coalesce({$byRule}, {$byHand})";
    }

    public function likeColumn(): string
    {
        return 'lower('.$this->column().')';
    }

    public function isText(): bool
    {
        return false;
    }

    public function isLive(): bool
    {
        return false;
    }

    public function isNumeric(): bool
    {
        return false;
    }

    public function storedValues(int|string $literal): array
    {
        return [(string) $literal];
    }

    public function englishName(): string
    {
        return __('Swarm Mode');
    }

    public function group(): string
    {
        return __('Swarm Mode');
    }

    public function hint(): string
    {
        return __('the tag of the Swarm Mode the request fell under, e.g. swarm IS NOT NULL for any');
    }

    public function ruleNote(): string
    {
        return 'the tag of the Swarm Mode a request fell under (its site, its path on every site, or that path on that site), or null; "swarm IS NOT NULL" is anything requested while its site or URL was in Swarm Mode. A rule over host or path turns Swarm Mode on with the SWARM clause. The Bouncer reads it at the door, so a BOUNCER rule can act on it at once.';
    }

    public function joins(): array
    {
        return [];
    }
}
