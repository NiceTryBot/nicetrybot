<?php

namespace App\Actions\Rules;

use App\Data\LiveRequest;
use App\Models\Team;
use Carbon\CarbonImmutable;

class ReachLiveVerdicts
{
    public function __construct(private EvaluateRule $evaluateRule)
    {
        //
    }

    /**
     * Judge the single-request rules the edge says a live request matches, and reach the verdict of each
     * that truly does, so the gym can take the client in on this very request. The edge's say-so only
     * picks which rules to judge: each is judged again here, and one the request doesn't match, can't be
     * decided from one request, or isn't the team's or enabled, is passed over. Returns the rules whose
     * verdicts the client now holds.
     *
     * @param  list<int>  $ruleIds
     * @return list<int>
     */
    public function handle(Team $team, LiveRequest $request, array $ruleIds, ?CarbonImmutable $now = null): array
    {
        if ($ruleIds === []) {
            return [];
        }

        $reached = [];

        foreach ($team->edgeRules()->where('enabled', true)->whereKey($ruleIds)->orderBy('id')->get() as $rule) {
            $script = rescue(fn () => $rule->script(), report: false);

            if ($script === null || ! $script->isLive() || ($script->where !== null && $request->evaluate($script->where) !== true)) {
                continue;
            }

            $this->evaluateRule->reachLive($rule, $request->ip, $request->ja4, $now);
            $reached[] = $rule->id;
        }

        return $reached;
    }
}
