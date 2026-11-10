<?php

namespace App\Support;

use App\Actions\Gym\SendRuleToRoutine;
use App\Data\GymCondition;
use App\Models\EdgeRule;
use App\Models\GymRoutine;
use App\Models\Team;
use Illuminate\Support\Facades\Gate;

/**
 * The core's GYM destination: GYM 'routine name' sends the rule's clients through a gym routine, making
 * the routine by name if the team doesn't have one yet (reusing a matching name, ignoring case).
 */
class GymRuleDestinationHandler implements RuleDestinationHandler
{
    public function __construct(private SendRuleToRoutine $sendRuleToRoutine)
    {
        //
    }

    public function keyword(): string
    {
        return 'GYM';
    }

    public function takesTarget(): bool
    {
        return true;
    }

    public function managedBy(Team $team): bool
    {
        return Gate::allows('create', [GymRoutine::class, $team]);
    }

    public function apply(EdgeRule $rule, ?string $target): void
    {
        $name = trim((string) $target);

        if ($name === '') {
            throw new RuleDestinationError(__('GYM needs a routine name, e.g. GYM \'Curl probes\'.'));
        }

        if ($this->sendRuleToRoutine->handle($rule, $name) === null) {
            throw new RuleDestinationError(__('The gym routine “:name” can’t take this rule without changing who else enters it. Use a different routine.', ['name' => $name]));
        }
    }

    public function remove(EdgeRule $rule): void
    {
        $rule->team->gymRoutines()->get()->each(function (GymRoutine $routine) use ($rule) {
            $entry = $routine->entry()->withoutVerdictsFrom($rule->id);

            if ($entry === null) {
                return;
            }

            $routine->update([
                'match_all' => $entry->matchAll,
                'conditions' => array_map(fn (GymCondition $condition) => $condition->toArray(), $entry->conditions),
                // Removing the only reason to enter would admit everyone; switch the routine off instead.
                'enabled' => $entry->isEveryone() ? false : $routine->enabled,
            ]);
        });
    }

    public function phrase(?string $target): string
    {
        return __('sends its clients through the gym routine “:name”', ['name' => trim((string) $target)]);
    }
}
