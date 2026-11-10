<?php

namespace App\Support;

use App\Data\GymEntry;
use App\Enums\GymExit;
use App\Enums\GymField;
use App\Enums\GymOperator;
use App\Models\GymRoutine;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;

/**
 * Gym routines as destinations: a routine that takes a rule's verdicts trains its clients, or, when it
 * never lets them on to the site, blocks them. A routine that's off does neither.
 */
class GymRuleDestinations implements RuleDestinationSource
{
    public function columns(): array
    {
        return [
            new RuleBoardColumn('gym', __('Gym'), __('Clients work through a routine, then carry on to the site.'), 'fire', order: 20, tone: 'amber'),
            new RuleBoardColumn('blocked', __('Blocked'), __('A routine turns clients away, so they never reach the site.'), 'hand-raised', order: 40, tone: 'rose'),
        ];
    }

    public function destinations(Team $team, Collection $rules): array
    {
        $routines = $team->gymRoutines()->get();
        $destinations = [];

        foreach ($rules as $rule) {
            foreach ($routines->filter(fn (GymRoutine $routine) => $routine->entry()->takesVerdictsFrom($rule->id)) as $routine) {
                $destinations[$rule->id][] = new RuleDestination(
                    column: match (true) {
                        ! $routine->enabled => null,
                        $routine->exit_action === GymExit::Continue => 'gym',
                        default => 'blocked',
                    },
                    label: $routine->enabled ? $routine->name : __(':name · off', ['name' => $routine->name]),
                    icon: 'fire',
                    url: route('gym.routines.edit', ['current_team' => $team, 'routine' => $routine]),
                    note: $routine->enabled ? $this->note($routine) : null,
                );
            }
        }

        return $destinations;
    }

    /**
     * Say what else decides who the routine takes, and how it answers when that isn't a 403.
     */
    private function note(GymRoutine $routine): ?string
    {
        $notes = $this->scope($routine->entry());

        if ($routine->exit_action === GymExit::NotFound) {
            $notes[] = __('Answers 404');
        }

        return $notes === [] ? null : implode(' · ', $notes);
    }

    /**
     * Describe the conditions a rule's clients must also meet, in an entry that needs all of them.
     *
     * @return list<string>
     */
    private function scope(GymEntry $entry): array
    {
        if (! $entry->matchAll) {
            return [];
        }

        $notes = [];

        foreach ($entry->conditions as $condition) {
            if ($condition->field === GymField::Verdict) {
                continue;
            }

            $notes[] = match (true) {
                $condition->field === GymField::Domain && $condition->operator === GymOperator::NotIn => trans_choice('Skips :count domain|Skips :count domains', count($condition->values)),
                $condition->field === GymField::Domain && $condition->operator === GymOperator::In => trans_choice('Only on :count domain|Only on :count domains', count($condition->values)),
                default => __('Other conditions too'),
            };
        }

        return array_values(array_unique($notes));
    }
}
