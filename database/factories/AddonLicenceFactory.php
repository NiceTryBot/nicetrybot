<?php

namespace Database\Factories;

use App\Models\AddonLicence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AddonLicence>
 */
class AddonLicenceFactory extends Factory
{
    /**
     * Define the model's default state: a paid add-on seen without a key, on its trial.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'key' => null,
            'licence_id' => null,
            'licensed_to' => null,
            'issued_on' => null,
            'updates_until' => null,
            'status' => AddonLicence::MISSING,
            'checked_at' => null,
            'first_seen_at' => now(),
            'entered_by_id' => null,
        ];
    }

    /**
     * A licence the marketplace said was valid, with a year of updates left.
     */
    public function valid(): static
    {
        return $this->state(fn () => [
            'key' => 'ntb1.'.fake()->sha256().'.'.fake()->sha256(),
            'licence_id' => strtoupper(fake()->unique()->bothify('01J####??????????????????')),
            'licensed_to' => fake()->name(),
            'issued_on' => now()->toDateString(),
            'updates_until' => now()->addYear()->toDateString(),
            'status' => AddonLicence::VALID,
            'checked_at' => now(),
        ]);
    }

    /**
     * A licence the marketplace revoked.
     */
    public function revoked(): static
    {
        return $this->valid()->state(fn () => ['status' => AddonLicence::REVOKED]);
    }
}
