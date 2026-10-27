<?php

namespace Database\Factories;

use App\Enums\GymExit;
use App\Models\GymRoutine;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GymRoutine>
 */
class GymRoutineFactory extends Factory
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
            'name' => 'Datacenter waiting room',
            'enabled' => true,
            'position' => 1,
            'match_all' => true,
            'conditions' => [['field' => 'network', 'operator' => 'in', 'values' => ['digitalocean', 'hetzner']]],
            'obstacles' => [['id' => Str::lower(Str::random(10)), 'type' => 'proof-of-work', 'config' => ['difficulty' => 12], 'escalate' => null]],
            'exit_action' => GymExit::Continue,
            'pass_seconds' => 3600,
            'one_try' => false,
        ];
    }

    /**
     * Admit visitors who meet the given condition rows.
     *
     * @param  list<array{field: string, operator: string, values: list<string>}>  $conditions
     */
    public function entering(array $conditions, bool $matchAll = true): static
    {
        return $this->state(fn (array $attributes) => ['conditions' => $conditions, 'match_all' => $matchAll]);
    }

    /**
     * Run the given obstacles, as type => config, each with a fresh id.
     *
     * @param  array<string, array<string, mixed>>  $obstacles
     */
    public function obstacles(array $obstacles): static
    {
        return $this->state(fn (array $attributes) => ['obstacles' => collect($obstacles)->map(fn (array $config, string $type) => [
            'id' => Str::lower(Str::random(10)),
            'type' => $type,
            'config' => $config,
            'escalate' => null,
        ])->values()->all()]);
    }

    /**
     * Indicate that the routine is switched off.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => ['enabled' => false]);
    }
}
