<?php

namespace Database\Factories;

use App\Enums\GymSessionStatus;
use App\Models\GymSession;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GymSession>
 */
class GymSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'token' => Str::random(40),
            'is_preview' => false,
            'status' => GymSessionStatus::Training,
            'destination' => 'https://shop.example.com/checkout',
            'client_ip' => '203.0.113.'.fake()->numberBetween(1, 254),
            'user_agent' => 'python-requests/2.32',
            'progress' => [],
            'escalations' => [],
            'hashes' => 0,
            'started_at' => now(),
            'last_seen_at' => now(),
        ];
    }

    /**
     * Indicate that the visitor cleared every obstacle.
     */
    public function cleared(): static
    {
        return $this->state(fn (array $attributes) => ['status' => GymSessionStatus::Cleared, 'finished_at' => now()]);
    }

    /**
     * Indicate that the session is an admin's preview.
     */
    public function preview(): static
    {
        return $this->state(fn (array $attributes) => ['is_preview' => true, 'destination' => null]);
    }
}
