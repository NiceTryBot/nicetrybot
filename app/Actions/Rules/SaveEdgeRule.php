<?php

namespace App\Actions\Rules;

use App\Data\RuleScript;
use App\Models\EdgeRule;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

class SaveEdgeRule
{
    /**
     * Save a rule: a new one for the team, or a change to one of its rules. The action and duration are
     * copied from the parsed script so lists can show them, and a new rule starts after the latest
     * logged request, so it only judges traffic from now on.
     *
     * @param  array{name: string, source: string, enabled: bool}  $attributes
     */
    public function handle(Team $team, ?EdgeRule $rule, array $attributes, RuleScript $script): EdgeRule
    {
        $attributes = [
            'name' => $attributes['name'],
            'source' => $attributes['source'],
            'enabled' => $attributes['enabled'],
            'action' => $script->action,
            'duration_seconds' => $script->durationSeconds,
        ];

        if ($rule === null) {
            return $team->edgeRules()->create([...$attributes, 'last_request_id' => (int) DB::table('edge_requests')->max('id')]);
        }

        $rule->update($attributes);

        return $rule;
    }
}
