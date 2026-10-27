<?php

namespace App\Mcp\Tools\Rules;

use App\Enums\ApiAbility;
use App\Mcp\Tools\Rules\Concerns\WorksWithRules;
use App\Mcp\Tools\TeamTool;
use App\Models\EdgeRule;
use App\Models\GymRoutine;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    List the team's rules with how each is doing: its script, whether it's on, its active verdicts (the
    IPs and fingerprints it has banned or tagged right now), every verdict it ever reached, the requests
    those verdicts matched, when it last matched, and which gyms take its verdicts. Pass `id` for one rule.
    Use it to answer "how is my rule doing?", or to find a rule's id before update-rule, delete-rule or
    backtest-rule.
    TEXT)]
#[IsReadOnly]
class ListRules extends TeamTool
{
    use WorksWithRules;

    protected ApiAbility $ability = ApiAbility::Read;

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Only this rule, by id. Leave out for every rule.'),
        ];
    }

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate(['id' => ['nullable', 'integer']]);

        Gate::forUser($this->user())->authorize('viewAny', [EdgeRule::class, $this->team()]);

        $rules = $this->team()->edgeRules()
            ->when($request->get('id') !== null, fn ($query) => $query->whereKey((int) $request->get('id')))
            ->orderBy('name')
            ->get();

        if ($request->get('id') !== null && $rules->isEmpty()) {
            return $this->ruleNotFound((int) $request->get('id'));
        }

        $routines = $this->team()->gymRoutines()->get();
        $verdicts = $this->verdictTotals($rules->modelKeys());

        return Response::structured([
            'rules' => $rules->map(fn (EdgeRule $rule) => [
                ...$this->ruleData($rule),
                ...$verdicts[$rule->id] ?? ['active_verdicts' => 0, 'total_verdicts' => 0, 'matched_requests' => 0, 'last_matched_at' => null],
                'gyms' => $routines
                    ->filter(fn (GymRoutine $routine) => $routine->entry()->takesVerdictsFrom($rule->id))
                    ->map(fn (GymRoutine $routine) => ['id' => $routine->id, 'name' => $routine->name, 'enabled' => $routine->enabled])
                    ->values()
                    ->all(),
            ])->values()->all(),
        ]);
    }

    /**
     * Total up the rules' verdicts: how many are in force, how many there have been, the requests they
     * matched and when the last one did, by rule id. Rules without verdicts are left out.
     *
     * @param  array<int, int|string>  $ruleIds
     * @return array<int, array{active_verdicts: int, total_verdicts: int, matched_requests: int, last_matched_at: string|null}>
     */
    private function verdictTotals(array $ruleIds): array
    {
        $verdicts = fn () => $this->team()->verdicts()->whereIn('edge_rule_id', $ruleIds)->toBase()->groupBy('edge_rule_id');

        $active = $this->team()->verdicts()->active()->whereIn('edge_rule_id', $ruleIds)->toBase()->groupBy('edge_rule_id')
            ->selectRaw('edge_rule_id, count(*) as active')
            ->pluck('active', 'edge_rule_id');

        return $verdicts()
            ->select('edge_rule_id')
            ->selectRaw('count(*) as total, coalesce(sum(matched_requests), 0) as matched, max(last_matched_at) as last_matched_at')
            ->get()
            ->mapWithKeys(fn (object $row) => [(int) $row->edge_rule_id => [
                'active_verdicts' => (int) $active->get($row->edge_rule_id, 0),
                'total_verdicts' => (int) $row->total,
                'matched_requests' => (int) $row->matched,
                'last_matched_at' => $row->last_matched_at === null ? null : Carbon::parse($row->last_matched_at)->toIso8601String(),
            ]])
            ->all();
    }
}
