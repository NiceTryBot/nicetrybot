<?php

namespace App\Mcp\Tools\Rules;

use App\Actions\Rules\SaveEdgeRule;
use App\Data\RuleScript;
use App\Data\RuleScriptSyntaxError;
use App\Enums\ApiAbility;
use App\Mcp\Tools\Rules\Concerns\WorksWithRules;
use App\Mcp\Tools\TeamTool;
use App\Models\EdgeRule;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description(<<<'TEXT'
    Save a new rule for the team. A rule is a script in NiceTryBot's rule language (call
    describe-rule-language for the grammar and fields), e.g.
    "TAG ip, ja4 AS 'dutch-visitors' FOR 1 DAY WHERE country = 'NL'". Every minute it reads the requests
    logged since it last ran and puts a verdict (ban or tag, for the script's duration) on each client it
    matches; it starts from now, never from past traffic. To send the clients it flags through a gym, pass
    gym_id (one of the team's gyms, from list-gyms) or gym_name (the team's gym by that name, or a new gym
    made for this rule); that needs the token's gym ability too, and the answer says if linking was
    skipped and why. Backtest the script with backtest-rule first and tell the person what it would catch.
    Change the new gym afterwards with update-gym, add-obstacle or remove-obstacle.
    TEXT)]
class CreateRule extends TeamTool
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
            'name' => $schema->string()->max(100)->description('A short name for the rule, like "Dutch visitors".')->required(),
            'source' => $schema->string()->description('The rule script, one clause per line, ideally starting with a "-- " comment saying who it catches.')->required(),
            'enabled' => $schema->boolean()->default(true)->description('Whether the rule runs. Defaults to true.'),
            'gym_id' => $schema->integer()->description('Send the clients the rule flags through this existing gym, by id from list-gyms.'),
            'gym_name' => $schema->string()->max(100)->description('Send the clients the rule flags through the team\'s gym with this name (ignoring case), or a new gym by this name, starting from the "Flagged by your rules" obstacles. Give this or gym_id, not both.'),
        ];
    }

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, SaveEdgeRule $saveEdgeRule): Response|ResponseFactory
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'source' => ['required', 'string', 'max:5000'],
            'enabled' => ['nullable', 'boolean'],
            'gym_id' => ['nullable', 'integer', 'prohibits:gym_name'],
            'gym_name' => ['nullable', 'string', 'max:100'],
        ], [
            'gym_id.prohibits' => 'Give gym_id or gym_name, not both.',
        ]);

        Gate::forUser($this->user())->authorize('create', [EdgeRule::class, $this->team()]);

        try {
            $script = RuleScript::parse($validated['source']);
        } catch (RuleScriptSyntaxError $error) {
            return $this->syntaxError($error);
        }

        $rule = $saveEdgeRule->handle($this->team(), null, [
            'name' => trim($validated['name']),
            'source' => $validated['source'],
            'enabled' => (bool) ($validated['enabled'] ?? true),
        ], $script);

        return Response::structured(array_filter([
            'rule' => $this->ruleData($rule),
            'gym' => $this->sendRuleToGym($rule, $validated['gym_id'] ?? null, $validated['gym_name'] ?? null),
        ], fn (mixed $value) => $value !== null));
    }
}
