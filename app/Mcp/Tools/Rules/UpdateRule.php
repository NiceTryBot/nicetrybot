<?php

namespace App\Mcp\Tools\Rules;

use App\Actions\Rules\FindStaleVerdicts;
use App\Actions\Rules\SaveEdgeRule;
use App\Data\RuleScript;
use App\Data\RuleScriptSyntaxError;
use App\Enums\ApiAbility;
use App\Mcp\Tools\Rules\Concerns\WorksWithRules;
use App\Mcp\Tools\TeamTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description(<<<'TEXT'
    Change one of the team's rules, by id from list-rules: its name, its script (source), or whether it
    runs (enabled). Leave out what stays the same. A new script applies to traffic from now on; when it
    no longer matches clients the old one put verdicts on, the answer counts those stale verdicts, and
    lift_stale_verdicts=true lifts them (ask the person first). Pass gym_id or gym_name to send the
    clients the rule flags through a gym too, as create-rule does. Backtest a new script with
    backtest-rule first.
    TEXT)]
#[IsIdempotent]
class UpdateRule extends TeamTool
{
    use WorksWithRules;

    protected ApiAbility $ability = ApiAbility::ManageRules;

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The rule to change, by id from list-rules.')->required(),
            'name' => $schema->string()->max(100)->description('A new name for the rule.'),
            'source' => $schema->string()->description('A new rule script, replacing the whole script.'),
            'enabled' => $schema->boolean()->description('true to run the rule, false to switch it off. Its verdicts stay until they expire or are lifted either way.'),
            'lift_stale_verdicts' => $schema->boolean()->default(false)->description('With a new source: lift the active verdicts the new script would not have reached. Defaults to false, which keeps them until they expire.'),
            'gym_id' => $schema->integer()->description('Also send the clients the rule flags through this existing gym, by id from list-gyms.'),
            'gym_name' => $schema->string()->max(100)->description('Also send them through the team\'s gym with this name (ignoring case), or a new gym by this name. Give this or gym_id, not both.'),
        ];
    }

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, SaveEdgeRule $saveEdgeRule, FindStaleVerdicts $findStaleVerdicts): Response|ResponseFactory
    {
        $validated = $request->validate([
            'id' => ['required', 'integer'],
            'name' => ['nullable', 'string', 'max:100'],
            'source' => ['nullable', 'string', 'max:5000'],
            'enabled' => ['nullable', 'boolean'],
            'lift_stale_verdicts' => ['nullable', 'boolean'],
            'gym_id' => ['nullable', 'integer', 'prohibits:gym_name'],
            'gym_name' => ['nullable', 'string', 'max:100'],
        ], [
            'gym_id.prohibits' => 'Give gym_id or gym_name, not both.',
        ]);

        $rule = $this->findRule($validated['id']);

        if ($rule === null) {
            return $this->ruleNotFound($validated['id']);
        }

        Gate::forUser($this->user())->authorize('update', $rule);

        $changes = array_filter([
            'name' => isset($validated['name']) ? trim($validated['name']) : null,
            'source' => $validated['source'] ?? null,
            'enabled' => $validated['enabled'] ?? null,
        ], fn (mixed $value) => $value !== null && $value !== '');

        if ($changes === [] && ! isset($validated['gym_id']) && blank($validated['gym_name'] ?? null)) {
            return Response::error(__('Nothing to change: give a name, source, enabled, gym_id or gym_name.'));
        }

        try {
            $script = RuleScript::parse($changes['source'] ?? $rule->source);
        } catch (RuleScriptSyntaxError $error) {
            return $this->syntaxError($error);
        }

        $sourceChanged = isset($changes['source']) && $changes['source'] !== $rule->source;

        $rule = $saveEdgeRule->handle($this->team(), $rule, [
            'name' => $changes['name'] ?? $rule->name,
            'source' => $changes['source'] ?? $rule->source,
            'enabled' => (bool) ($changes['enabled'] ?? $rule->enabled),
        ], $script);

        $result = ['rule' => $this->ruleData($rule)];

        if ($sourceChanged) {
            $stale = $findStaleVerdicts->handle($rule);
            $lift = (bool) ($validated['lift_stale_verdicts'] ?? false) && $stale->isNotEmpty();

            if ($lift) {
                $this->team()->verdicts()->active()->whereKey($stale->modelKeys())->update(['lifted_at' => now()]);
            }

            $result['stale_verdicts'] = ['count' => $stale->count(), 'lifted' => $lift];
        }

        if (($gym = $this->sendRuleToGym($rule, $validated['gym_id'] ?? null, $validated['gym_name'] ?? null)) !== null) {
            $result['gym'] = $gym;
        }

        return Response::structured($result);
    }
}
