<?php

namespace App\Mcp\Tools\Gym;

use App\Enums\ApiAbility;
use App\Gym\Contracts\Obstacle;
use App\Gym\ObstacleRegistry;
use App\Gym\ObstacleSetting;
use App\Mcp\Tools\TeamTool;
use App\Models\GymRoutine;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    List the obstacle types a gym can use: each one's type key, name, what the visitor goes through, and
    its settings (key, type range|choice|toggle|text, default, options for a choice, min/max/step for a
    range, help). can_escalate says whether it can flag a visitor as automated, and so take an `escalate`
    obstacle that's added when it does. Call it before create-gym, update-gym or add-obstacle: obstacles
    are given as {type, config: {setting: value}, escalate}, and settings left out take their default.
    TEXT)]
#[IsReadOnly]
class ListObstacleTypes extends TeamTool
{
    protected ApiAbility $ability = ApiAbility::Read;

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, ObstacleRegistry $registry): Response|ResponseFactory
    {
        Gate::forUser($this->user())->authorize('viewAny', [GymRoutine::class, $this->team()]);

        return Response::structured([
            'obstacle_types' => array_values(array_map(fn (Obstacle $obstacle) => [
                'type' => $obstacle->key(),
                'name' => $obstacle->name(),
                'tagline' => $obstacle->tagline(),
                'can_escalate' => $obstacle->flagsWhen() !== null,
                'flags_when' => $obstacle->flagsWhen(),
                'settings' => array_map(fn (ObstacleSetting $setting) => array_filter([
                    'key' => $setting->key,
                    'label' => $setting->label,
                    'type' => $setting->type,
                    'default' => $setting->default,
                    'options' => $setting->type === 'choice' ? $setting->options : null,
                    'min' => $setting->type === 'range' ? $setting->min : null,
                    'max' => in_array($setting->type, ['range', 'text'], true) ? $setting->max : null,
                    'step' => $setting->type === 'range' ? $setting->step : null,
                    'unit' => $setting->unit,
                    'help' => $setting->help,
                ], fn (mixed $value) => $value !== null), $obstacle->settings()),
            ], $registry->all())),
        ]);
    }
}
