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

#[Description(<<<'TEXT'
    Take one obstacle out of one of the team's gyms: the gym's id and the obstacle's id, both from
    list-gyms. The other obstacles keep their order. A gym with no obstacles left sends visitors straight
    to its exit.
    TEXT)]
class RemoveObstacle extends TeamTool
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
            'gym_id' => $schema->integer()->description('The gym to take it out of, by id from list-gyms.')->required(),
            'obstacle_id' => $schema->string()->description('The obstacle\'s id within the gym, from list-gyms (not its type).')->required(),
        ];
    }

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, SaveGymRoutine $saveGymRoutine): Response|ResponseFactory
    {
        $validated = $request->validate([
            'gym_id' => ['required', 'integer'],
            'obstacle_id' => ['required', 'string'],
        ]);

        $routine = $this->findRoutine($validated['gym_id']);

        if ($routine === null) {
            return $this->routineNotFound($validated['gym_id']);
        }

        Gate::forUser($this->user())->authorize('update', $routine);

        $obstacles = array_values(array_filter($routine->obstacles, fn (array $entry) => (string) $entry['id'] !== $validated['obstacle_id']));

        if (count($obstacles) === count($routine->obstacles)) {
            return Response::error(__(':gym has no obstacle with id :id. Its obstacles are :obstacles.', [
                'gym' => '"'.$routine->name.'"',
                'id' => $validated['obstacle_id'],
                'obstacles' => $routine->obstacles === [] ? __('none') : collect($routine->obstacles)->map(fn (array $entry) => $entry['id'].' ('.$entry['type'].')')->implode(', '),
            ]));
        }

        $saveGymRoutine->handle($this->team(), $routine, [...$this->currentAttributes($routine), 'obstacles' => $obstacles]);

        return Response::structured(['gym' => $this->routineData($routine)]);
    }
}
