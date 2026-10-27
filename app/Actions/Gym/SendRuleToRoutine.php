<?php

namespace App\Actions\Gym;

use App\Data\GymCondition;
use App\Gym\RoutineTemplates;
use App\Models\EdgeRule;
use App\Models\GymRoutine;

class SendRuleToRoutine
{
    /**
     * Send the clients a rule flags through a gym routine: one of the team's, by adding the rule to its
     * entry, or a new one by name, entered by the rule's verdicts and starting from the "Flagged by your
     * rules" obstacles. A name the team already has a routine for (ignoring case) uses that routine.
     * Returns the routine, or null when the team's routine can't take the rule without changing who else
     * enters it.
     */
    public function handle(EdgeRule $rule, GymRoutine|string $routine): ?GymRoutine
    {
        if (is_string($routine)) {
            $existing = $rule->team->gymRoutines()->whereRaw('lower(name) = ?', [mb_strtolower(trim($routine))])->first();

            if ($existing === null) {
                return $rule->team->gymRoutines()->create([
                    ...RoutineTemplates::forRule($rule),
                    'name' => trim($routine),
                    'enabled' => true,
                    'position' => (int) $rule->team->gymRoutines()->max('position') + 1,
                ]);
            }

            $routine = $existing;
        }

        $entry = $routine->entry()->withVerdictsFrom($rule->id);

        if ($entry === null) {
            return null;
        }

        $routine->update([
            'match_all' => $entry->matchAll,
            'conditions' => array_map(fn (GymCondition $condition) => $condition->toArray(), $entry->conditions),
        ]);

        return $routine;
    }
}
