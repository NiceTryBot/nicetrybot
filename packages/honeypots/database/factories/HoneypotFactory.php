<?php

namespace NiceTry\Honeypots\Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use NiceTry\Honeypots\Models\Honeypot;

/**
 * @extends Factory<Honeypot>
 */
class HoneypotFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Honeypot>
     */
    protected $model = Honeypot::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->words(2, true),
            'origin' => 'https://'.fake()->domainWord().'.honeypot.example',
            'timeout_seconds' => 30,
        ];
    }
}
