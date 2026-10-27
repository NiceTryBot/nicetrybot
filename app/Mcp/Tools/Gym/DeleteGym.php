<?php

namespace App\Mcp\Tools\Gym;

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
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description(<<<'TEXT'
    Delete one of the team's gyms for good, by id from list-gyms. Visitors it took stop going through it
    (other gyms they match still apply). To pause it instead, call update-gym with enabled=false. Confirm
    with the person first.
    TEXT)]
#[IsDestructive]
class DeleteGym extends TeamTool
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
            'id' => $schema->integer()->description('The gym to delete, by id from list-gyms.')->required(),
        ];
    }

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate(['id' => ['required', 'integer']]);

        $routine = $this->findRoutine($validated['id']);

        if ($routine === null) {
            return $this->routineNotFound($validated['id']);
        }

        Gate::forUser($this->user())->authorize('delete', $routine);

        $routine->delete();

        return Response::structured(['deleted' => ['id' => $routine->id, 'name' => $routine->name]]);
    }
}
