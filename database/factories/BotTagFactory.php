<?php

namespace Database\Factories;

use App\Models\BotTag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BotTag>
 */
class BotTagFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word().'-probe',
        ];
    }
}
