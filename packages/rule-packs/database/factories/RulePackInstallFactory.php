<?php

namespace NiceTry\RulePacks\Database\Factories;

use App\Models\EdgeRule;
use App\Models\GymRoutine;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use NiceTry\RulePacks\Models\RulePackInstall;
use NiceTry\RulePacks\Support\PackFingerprint;

/**
 * @extends Factory<RulePackInstall>
 */
class RulePackInstallFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<RulePackInstall>
     */
    protected $model = RulePackInstall::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'pack' => fake()->slug(2),
            'item' => fake()->slug(2),
            'version' => 1,
            'fingerprint' => md5(fake()->uuid()),
        ];
    }

    /**
     * Indicate that the pack installed this rule or routine, as it is now.
     */
    public function of(EdgeRule|GymRoutine $item): static
    {
        return $this->state(fn () => [
            'team_id' => $item->team_id,
            'edge_rule_id' => $item instanceof EdgeRule ? $item->id : null,
            'gym_routine_id' => $item instanceof GymRoutine ? $item->id : null,
            'fingerprint' => PackFingerprint::of($item),
        ]);
    }
}
