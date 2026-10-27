<?php

namespace App\Mcp\Tools\Rules;

use App\Enums\ApiAbility;
use App\Enums\GymField;
use App\Mcp\Tools\Rules\Concerns\WorksWithRules;
use App\Mcp\Tools\TeamTool;
use App\Models\GymRoutine;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description(<<<'TEXT'
    Delete one of the team's rules for good, by id from list-rules. It stops reaching verdicts; to pause
    it instead, call update-rule with enabled=false. Confirm with the person first. The answer lists gyms
    whose entry still names the deleted rule, which nobody enters through any more: fix them with
    update-gym or delete-gym.
    TEXT)]
#[IsDestructive]
class DeleteRule extends TeamTool
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
            'id' => $schema->integer()->description('The rule to delete, by id from list-rules.')->required(),
        ];
    }

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate(['id' => ['required', 'integer']]);

        $rule = $this->findRule($validated['id']);

        if ($rule === null) {
            return $this->ruleNotFound($validated['id']);
        }

        Gate::forUser($this->user())->authorize('delete', $rule);

        $gyms = $this->team()->gymRoutines()->get()
            ->filter(fn (GymRoutine $routine) => collect($routine->conditions)->contains(
                fn (array $condition) => $condition['field'] === GymField::Verdict->value && in_array((string) $rule->id, $condition['values'], true),
            ))
            ->map(fn (GymRoutine $routine) => ['id' => $routine->id, 'name' => $routine->name])
            ->values()
            ->all();

        $rule->delete();

        return Response::structured([
            'deleted' => ['id' => $rule->id, 'name' => $rule->name],
            'gyms_still_naming_it' => $gyms,
        ]);
    }
}
