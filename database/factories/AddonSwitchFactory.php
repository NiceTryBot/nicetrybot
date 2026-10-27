<?php

namespace Database\Factories;

use App\Models\AddonSwitch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AddonSwitch>
 */
class AddonSwitchFactory extends Factory
{
    /**
     * Define the model's default state: an add-on turned off.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'turned_off_at' => now(),
            'turned_off_by_id' => null,
            'removal_requested_at' => null,
            'removal_requested_by_id' => null,
        ];
    }

    /**
     * An add-on turned off and waiting for a deploy to remove it.
     */
    public function awaitingRemoval(): static
    {
        return $this->state(fn () => ['removal_requested_at' => now()]);
    }
}
