<?php

namespace App\Mcp\Tools\Gym;

use App\Actions\Gym\SaveGymRoutine;
use App\Enums\ApiAbility;
use App\Gym\GymPipeline;
use App\Mcp\Tools\Gym\Concerns\WorksWithGyms;
use App\Mcp\Tools\TeamTool;
use App\Models\GymRoutine;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description(<<<'TEXT'
    Make a new gym (gym routine) for the team, at the end of its stack. Give who enters as conditions,
    e.g. every visitor from the Netherlands is [{"field": "country", "operator": "in", "values": ["NL"]}]
    (ISO 3166 codes: NL is the Netherlands, NE is Niger); and the obstacles they go through as
    [{"type": "waiting-room", "config": {"seconds": 30}}] (call list-obstacle-types for types and
    settings). Defaults: enabled, match_all true, exit_action continue, pass_seconds 3600, one_try false.
    To send the clients a rule flags here, use create-rule or update-rule with gym_id instead of a verdict
    condition. Visitors only meet gyms on domains in Enforce mode. The answer shows the saved gym, with
    who_enters in words: check it says what the person asked for.
    TEXT)]
class CreateGym extends TeamTool
{
    use WorksWithGyms;

    protected ApiAbility $ability = ApiAbility::ManageGym;

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $properties = $this->gymProperties($schema);
        $properties['name'] = $properties['name']->required();

        return $properties;
    }

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, SaveGymRoutine $saveGymRoutine): Response|ResponseFactory
    {
        $validated = $request->validate($this->gymRules(creating: true), $this->gymMessages());

        Gate::forUser($this->user())->authorize('create', [GymRoutine::class, $this->team()]);

        $routine = $saveGymRoutine->handle($this->team(), null, [
            'name' => trim($validated['name']),
            'enabled' => (bool) ($validated['enabled'] ?? true),
            'match_all' => (bool) ($validated['match_all'] ?? true),
            'conditions' => $this->validatedConditions($request->get('conditions') ?? []),
            'obstacles' => $this->validatedObstacles($request->get('obstacles') ?? []),
            'exit_action' => $this->exitFrom($validated['exit_action'] ?? 'continue'),
            'pass_seconds' => (int) ($validated['pass_seconds'] ?? GymPipeline::DEFAULT_PASS_SECONDS),
            'one_try' => (bool) ($validated['one_try'] ?? false),
        ], $saveGymRoutine->defaultAddons());

        return Response::structured(['gym' => $this->routineData($routine)]);
    }
}
