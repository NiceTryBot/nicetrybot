<?php

namespace NiceTry\SwarmMode\Rules;

use App\Data\LiveRequest;
use App\Models\Domain;
use App\Models\ScopeVerdict;
use App\Support\DoorField;
use App\Support\RuleFieldDefinition;
use Illuminate\Support\Facades\DB;
use NiceTry\SwarmMode\Models\ManualSwarm;
use NiceTry\SwarmMode\Models\SwarmRule;

/**
 * Tells the Bouncer's door whether the request in front of it falls under Swarm Mode, so a BOUNCER rule
 * reading `swarm` acts on the very request, not a minute later from the logs.
 */
class SwarmDoorField implements DoorField
{
    public function field(): RuleFieldDefinition
    {
        return SwarmRuleField::Swarm;
    }

    /**
     * The tag of the Swarm Mode covering this site and path, a swarm rule's or one turned on by hand, or
     * null. A path the logs have never seen can only be covered by its site's.
     */
    public function value(Domain $domain, LiveRequest $live): ?string
    {
        $pathId = DB::table('request_paths')->where('hash', md5($live->path))->value('id');

        $pathId = $pathId === null ? null : (int) $pathId;

        return ScopeVerdict::query()
            ->active()
            ->where('team_id', $domain->team_id)
            ->whereIn('edge_rule_id', SwarmRule::query()->select('edge_rule_id'))
            ->covering($domain->id, $pathId)
            ->value('tag')
            ?? (ManualSwarm::query()->active()->where('team_id', $domain->team_id)->covering($domain->id, $pathId)->exists() ? ManualSwarm::TAG : null);
    }
}
