<?php

namespace NiceTry\Lockdown\Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use NiceTry\Lockdown\Models\Lockdown;

/**
 * @extends Factory<Lockdown>
 */
class LockdownFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Lockdown>
     */
    protected $model = Lockdown::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'secret' => bin2hex(random_bytes(32)),
        ];
    }

    /**
     * Indicate that the lockdown is engaged.
     */
    public function engaged(): static
    {
        return $this->state(fn () => ['engaged_at' => now()]);
    }
}
