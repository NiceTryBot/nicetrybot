<?php

namespace NiceTry\Tarpit\Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use NiceTry\Tarpit\Enums\TarpitAfterWait;
use NiceTry\Tarpit\Enums\TarpitWhenFull;
use NiceTry\Tarpit\Models\Tarpit;

/**
 * @extends Factory<Tarpit>
 */
class TarpitFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Tarpit>
     */
    protected $model = Tarpit::class;

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
            'enabled' => true,
            'all_domains' => true,
            'free_tries' => 1,
            'delays' => Tarpit::DEFAULT_DELAYS,
            'max_delay_seconds' => 30,
            'max_held' => 100,
            'when_full' => TarpitWhenFull::Reject,
            'after_wait' => TarpitAfterWait::Continue,
            'forget_after_seconds' => 900,
            'takes_gym_no_shows' => false,
        ];
    }

    /**
     * Indicate that the tarpit is switched off.
     */
    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }

    /**
     * Indicate that the tarpit takes the gym's no-shows.
     */
    public function takingGymNoShows(): static
    {
        return $this->state(fn () => ['takes_gym_no_shows' => true]);
    }
}
