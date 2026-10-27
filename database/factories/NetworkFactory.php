<?php

namespace Database\Factories;

use App\Enums\NetworkKind;
use App\Models\Network;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Network>
 */
class NetworkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'asn' => fake()->unique()->numberBetween(100000, 400000),
            'name' => fake()->company(),
            'kind' => NetworkKind::Unknown,
        ];
    }
}
