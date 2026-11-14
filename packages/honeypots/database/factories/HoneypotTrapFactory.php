<?php

namespace NiceTry\Honeypots\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use NiceTry\Honeypots\Models\Honeypot;
use NiceTry\Honeypots\Models\HoneypotTrap;

/**
 * @extends Factory<HoneypotTrap>
 */
class HoneypotTrapFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<HoneypotTrap>
     */
    protected $model = HoneypotTrap::class;

    /**
     * Define the model's default state: a trap on the team's honeypot, team-wide.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'honeypot_id' => Honeypot::factory(),
            'team_id' => fn (array $attributes) => Honeypot::find($attributes['honeypot_id'])?->team_id,
            'domain_id' => null,
            'patterns' => ['/wp-admin*', 'api/*'],
            'enabled' => true,
        ];
    }

    /**
     * Indicate that the trap is switched off.
     */
    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }
}
