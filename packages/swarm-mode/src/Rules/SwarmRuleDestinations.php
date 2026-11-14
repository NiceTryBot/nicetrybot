<?php

namespace NiceTry\SwarmMode\Rules;

use App\Models\ScopeVerdict;
use App\Models\Team;
use App\Support\RuleBoardColumn;
use App\Support\RuleDestination;
use App\Support\RuleDestinationSource;
use Illuminate\Database\Eloquent\Collection;
use NiceTry\SwarmMode\Models\SwarmRule;

/**
 * Swarm Mode on the Rules page's board, under Modes: a rule with a SWARM clause puts the sites or paths
 * it matches into Swarm Mode, and its card says where it's on right now.
 */
class SwarmRuleDestinations implements RuleDestinationSource
{
    public function columns(): array
    {
        return [
            new RuleBoardColumn('swarm', __('Swarm Mode'), __('A site or URL many IPs hit errors on at once goes into Swarm Mode for a while. Other rules can act on everyone there.'), 'bolt', order: 60, tone: 'sky', tab: RuleBoardColumn::TAB_MODES),
        ];
    }

    public function destinations(Team $team, Collection $rules): array
    {
        $linked = SwarmRule::query()->whereIn('edge_rule_id', $rules->modelKeys())->pluck('edge_rule_id');

        if ($linked->isEmpty()) {
            return [];
        }

        $active = ScopeVerdict::query()->active()->whereIn('edge_rule_id', $linked)->get(['edge_rule_id', 'domain_id', 'path_id'])->groupBy('edge_rule_id');
        $destinations = [];

        foreach ($linked as $ruleId) {
            $on = $active->get($ruleId, collect());

            $destinations[$ruleId][] = new RuleDestination(
                column: 'swarm',
                label: __('Swarm Mode'),
                icon: 'bolt',
                url: route('swarm-mode.index', ['current_team' => $team]),
                note: $on->isEmpty()
                    ? __('Off right now')
                    : trans_choice('On now for :count site or URL|On now for :count sites or URLs', $on->count()),
            );
        }

        return $destinations;
    }
}
