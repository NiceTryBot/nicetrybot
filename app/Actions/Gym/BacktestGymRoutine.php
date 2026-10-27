<?php

namespace App\Actions\Gym;

use App\Actions\Rules\BacktestRule;
use App\Data\GymEntry;
use App\Data\RuleBacktest;
use App\Data\RuleScript;
use App\Enums\RuleAction;
use App\Enums\RuleSubject;
use App\Models\Team;

class BacktestGymRoutine
{
    public function __construct(private BacktestRule $backtestRule)
    {
        //
    }

    /**
     * Show who a routine's entry conditions would have sent to the gym over the last few days, by IP,
     * busiest first. The conditions compile into the Rules engine's expression tree, so this is the
     * same dry run a rule gets.
     */
    public function handle(Team $team, GymEntry $entry, int $days = 7): RuleBacktest
    {
        $script = new RuleScript(
            action: RuleAction::Tag,
            subjects: [RuleSubject::Ip],
            tag: 'gym',
            durationSeconds: null,
            where: $entry->toExpression(),
            windowSeconds: null,
            having: null,
        );

        return $this->backtestRule->handle($team, $script, $days);
    }
}
