<?php

namespace Database\Factories;

use App\Data\RuleScript;
use App\Enums\RuleAction;
use App\Models\EdgeRule;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EdgeRule>
 */
class EdgeRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => 'Probe scanners',
            'source' => "BAN ip, ja4 FOR 30 DAYS\nWHERE category = 'probe'",
            'enabled' => true,
            'action' => RuleAction::Ban,
            'duration_seconds' => 30 * 86400,
            'last_request_id' => 0,
        ];
    }

    /**
     * Use the given rule script, copying its action and duration as saving does.
     */
    public function script(string $source): static
    {
        $script = RuleScript::parse($source);

        return $this->state(fn (array $attributes) => [
            'source' => $source,
            'action' => $script->action,
            'duration_seconds' => $script->durationSeconds,
        ]);
    }

    /**
     * Indicate that the rule is switched off.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => ['enabled' => false]);
    }
}
