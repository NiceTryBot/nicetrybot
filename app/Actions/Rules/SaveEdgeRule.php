<?php

namespace App\Actions\Rules;

use App\Data\RuleScript;
use App\Models\EdgeRule;
use App\Models\Team;
use App\Support\RuleDestinationError;
use App\Support\RuleDestinationHandlers;
use App\Support\ScopedRuleDestination;
use Illuminate\Support\Facades\DB;

class SaveEdgeRule
{
    /**
     * Save a rule: a new one for the team, or a change to one of its rules. The action and duration are
     * copied from the parsed script so lists can show them, and a new rule starts after the latest
     * logged request, so it only judges traffic from now on. The script's destination clauses are then
     * reconciled: a destination it names is linked (created by name if needed), and one it no longer
     * names is unlinked. A destination the person can't manage (e.g. the blackhole, for non-admins) is
     * left exactly as it is, and naming it is refused. The whole save rolls back if a destination can't
     * be applied.
     *
     * @param  array{name: string, source: string, enabled: bool}  $attributes
     *
     * @throws RuleDestinationError
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

        return DB::transaction(function () use ($team, $rule, $attributes, $script) {
            $rule = $rule === null
                ? $team->edgeRules()->create([...$attributes, 'last_request_id' => (int) DB::table('edge_requests')->max('id')])
                : tap($rule)->update($attributes);

            $this->reconcileDestinations($rule, $script);

            return $rule;
        });
    }

    /**
     * Link the destinations the script names and unlink the ones it no longer names, through each
     * installed destination handler. Rule packs call this too, for the rules they install.
     *
     * @throws RuleDestinationError
     */
    public function reconcileDestinations(EdgeRule $rule, RuleScript $script): void
    {
        foreach (app(RuleDestinationHandlers::class)->all() as $handler) {
            $named = array_key_exists($handler->keyword(), $script->destinations);

            if (! $handler->managedBy($rule->team)) {
                if ($named) {
                    throw new RuleDestinationError(__('You can’t send clients to :keyword on this team.', ['keyword' => $handler->keyword()]));
                }

                continue;
            }

            if ($named && $script->isScoped() !== $handler instanceof ScopedRuleDestination) {
                throw new RuleDestinationError($script->isScoped()
                    ? __(':keyword acts on clients, so its rule tags ip or ja4, not host or path.', ['keyword' => $handler->keyword()])
                    : __(':keyword acts on a site or path, so its rule tags host or path, not ip or ja4.', ['keyword' => $handler->keyword()]));
            }

            $named
                ? $handler->apply($rule, $script->destinations[$handler->keyword()])
                : $handler->remove($rule);
        }
    }
}
