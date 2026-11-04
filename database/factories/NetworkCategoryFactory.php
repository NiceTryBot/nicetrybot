<?php

namespace Database\Factories;

use App\Enums\NetworkCategory as Category;
use App\Models\NetworkCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NetworkCategory>
 */
class NetworkCategoryFactory extends Factory
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
            'category' => Category::Hosting,
            'ranges' => 4,
            'addresses' => 1024,
            'is_override' => false,
        ];
    }

    /**
     * Indicate that an administrator set the category by hand.
     */
    public function override(): static
    {
        return $this->state(fn () => ['is_override' => true]);
    }
}
