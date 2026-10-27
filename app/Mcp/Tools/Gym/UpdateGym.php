<?php

namespace App\Mcp\Tools\Gym;

use App\Actions\Gym\SaveGymRoutine;
use App\Enums\ApiAbility;
use App\Mcp\Tools\Gym\Concerns\WorksWithGyms;
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
    Change one of the team's gyms, by id from list-gyms. Give only what changes. conditions, when given,
    replace who enters (an empty list lets every visitor in); obstacles, when given, replace the whole
    list in the new order (an obstacle whose type the gym already had keeps its id, so visitors part way
    through keep their progress). To add or remove a single obstacle, add-obstacle and remove-obstacle are
    simpler; to change one obstacle's settings, send the full obstacles list with its new config. Set
    enabled=false to pause a gym. Visitors already in the gym get the new pipeline at their next obstacle.
    TEXT)]
#[IsIdempotent]
class UpdateGym extends TeamTool
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
        return [
            'id' => $schema->integer()->description('The gym to change, by id from list-gyms.')->required(),
            ...$this->gymProperties($schema),
        ];
    }

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, SaveGymRoutine $saveGymRoutine): Response|ResponseFactory
    {
        $validated = $request->validate(['id' => ['required', 'integer'], ...$this->gymRules(creating: false)], $this->gymMessages());

        $routine = $this->findRoutine($validated['id']);

        if ($routine === null) {
            return $this->routineNotFound($validated['id']);
        }

        Gate::forUser($this->user())->authorize('update', $routine);

        $given = fn (string $key): bool => array_key_exists($key, $request->all()) && $request->get($key) !== null;

        if (! collect(['name', 'enabled', 'match_all', 'conditions', 'obstacles', 'exit_action', 'pass_seconds', 'one_try'])->contains($given)) {
            return Response::error(__('Nothing to change: give at least one of name, enabled, match_all, conditions, obstacles, exit_action, pass_seconds or one_try.'));
        }

        $saveGymRoutine->handle($this->team(), $routine, [
            'name' => $given('name') ? trim($validated['name']) : $routine->name,
            'enabled' => $given('enabled') ? (bool) $validated['enabled'] : $routine->enabled,
            'match_all' => $given('match_all') ? (bool) $validated['match_all'] : $routine->match_all,
            'conditions' => $given('conditions') ? $this->validatedConditions($request->get('conditions')) : $routine->conditions,
            'obstacles' => $given('obstacles') ? $this->validatedObstacles($request->get('obstacles'), $routine->obstacles) : $routine->obstacles,
            'exit_action' => $given('exit_action') ? $this->exitFrom($validated['exit_action']) : $routine->exit_action,
            'pass_seconds' => $given('pass_seconds') ? (int) $validated['pass_seconds'] : $routine->pass_seconds,
            'one_try' => $given('one_try') ? (bool) $validated['one_try'] : $routine->one_try,
        ]);

        return Response::structured(['gym' => $this->routineData($routine)]);
    }
}
