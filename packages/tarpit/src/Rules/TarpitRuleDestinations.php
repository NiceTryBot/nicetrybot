<?php

namespace NiceTry\Tarpit\Rules;

use App\Models\Team;
use App\Support\RuleBoardColumn;
use App\Support\RuleDestination;
use App\Support\RuleDestinationSource;
use Illuminate\Database\Eloquent\Collection;

/**
 * Tarpits on the Rules page's board: a tarpit a rule is connected to holds its clients. One that's off doesn't.
 */
class TarpitRuleDestinations implements RuleDestinationSource
{
    public function columns(): array
    {
        return [
            new RuleBoardColumn('tarpit', __('Tarpit'), __('Clients are held, so every request costs them time.'), 'clock', order: 30, tone: 'violet'),
        ];
    }

    public function destinations(Team $team, Collection $rules): array
    {
        $destinations = [];

        foreach ($team->tarpits()->with('rules:id')->withCount('domains')->get() as $tarpit) {
            foreach ($tarpit->rules as $rule) {
                $destinations[$rule->id][] = new RuleDestination(
                    column: $tarpit->enabled ? 'tarpit' : null,
                    label: $tarpit->enabled ? $tarpit->name : __(':name · off', ['name' => $tarpit->name]),
                    icon: 'clock',
                    url: route('tarpits.index', ['current_team' => $team]),
                    note: $tarpit->all_domains || ! $tarpit->enabled ? null : trans_choice('Only on :count domain|Only on :count domains', $tarpit->domains_count),
                );
            }
        }

        return $destinations;
    }
}
