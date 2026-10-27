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
    Add one obstacle to one of the team's gyms, by gym id from list-gyms: e.g. add a waiting room to the
    "Bad actor" gym with {"gym_id": 4, "type": "waiting-room", "config": {"seconds": 60}}. It goes last
    unless you give a position (1 is first). A gym takes at most one of each obstacle type; to change the
    settings of one it already has, use update-gym with the full obstacles list. Call
    list-obstacle-types for the types and their settings.
    TEXT)]
class AddObstacle extends TeamTool
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
            'gym_id' => $schema->integer()->description('The gym to add to, by id from list-gyms.')->required(),
            'type' => $schema->string()->description('The obstacle type key, from list-obstacle-types.')->required(),
            'config' => $schema->object()->description('Settings by key, from list-obstacle-types. Settings left out take their default.'),
            'escalate' => $schema->string()->description('For an obstacle that can flag visitors as automated: the obstacle type to add when it does. Must not already be in the gym.'),
            'position' => $schema->integer()->min(1)->description('Where it goes in the gym, 1 being the first obstacle visitors meet. Defaults to last.'),
        ];
    }

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, SaveGymRoutine $saveGymRoutine): Response|ResponseFactory
    {
        $validated = $request->validate([
            'gym_id' => ['required', 'integer'],
            'type' => ['required', 'string'],
            'config' => ['nullable', 'array'],
            'escalate' => ['nullable', 'string'],
            'position' => ['nullable', 'integer', 'min:1'],
        ]);

        $routine = $this->findRoutine($validated['gym_id']);

        if ($routine === null) {
            return $this->routineNotFound($validated['gym_id']);
        }

        Gate::forUser($this->user())->authorize('update', $routine);

        $obstacles = $routine->obstacles;
        $types = array_column($obstacles, 'type');

        if (in_array($validated['type'], $types, true)) {
            return Response::error(__(':gym already has a :type. A gym takes at most one of each obstacle type; to change its settings, use update-gym with the full obstacles list.', ['gym' => '"'.$routine->name.'"', 'type' => $validated['type']]));
        }

        $entry = $this->validatedObstacle([
            'type' => $validated['type'],
            'config' => $request->get('config') ?? [],
            'escalate' => $validated['escalate'] ?? null,
        ], count($obstacles) + 1, [...$types, $validated['type']]);

        $index = min(count($obstacles), max(0, (int) ($validated['position'] ?? count($obstacles) + 1) - 1));
        array_splice($obstacles, $index, 0, [['id' => $this->newObstacleId(), ...$entry]]);

        $saveGymRoutine->handle($this->team(), $routine, [...$this->currentAttributes($routine), 'obstacles' => $obstacles]);

        return Response::structured(['gym' => $this->routineData($routine)]);
    }
}
