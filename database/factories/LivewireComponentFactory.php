<?php

namespace Database\Factories;

use App\Models\LivewireComponent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LivewireComponent>
 */
class LivewireComponentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'app.'.fake()->unique()->slug(2).'.login',
        ];
    }
}
