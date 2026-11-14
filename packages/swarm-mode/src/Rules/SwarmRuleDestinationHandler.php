<?php

namespace NiceTry\SwarmMode\Rules;

use App\Models\EdgeRule;
use App\Models\GymRoutine;
use App\Models\Team;
use App\Support\RuleDestinationHandler;
use App\Support\ScopedRuleDestination;
use Illuminate\Support\Facades\Gate;
use NiceTry\SwarmMode\Models\SwarmRule;

/**
 * The SWARM destination: the sites and paths a rule over host and path matches go into Swarm Mode for as
 * long as its verdict lasts. It acts on a scope, not on clients, so only a rule over host or path can
 * name it (SaveEdgeRule refuses the rest).
 */
class SwarmRuleDestinationHandler implements RuleDestinationHandler, ScopedRuleDestination
{
    public function keyword(): string
    {
        return 'SWARM';
    }

    public function takesTarget(): bool
    {
        return false;
    }

    public function managedBy(Team $team): bool
    {
        return Gate::allows('create', [GymRoutine::class, $team]);
    }

    public function apply(EdgeRule $rule, ?string $target): void
    {
        SwarmRule::query()->firstOrCreate(['edge_rule_id' => $rule->id]);
    }

    public function remove(EdgeRule $rule): void
    {
        SwarmRule::query()->where('edge_rule_id', $rule->id)->delete();
    }

    public function phrase(?string $target): string
    {
        return __('puts the site or path into Swarm Mode');
    }
}
