<?php

namespace Database\Factories;

use App\Models\LiveTail;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LiveTail>
 */
class LiveTailFactory extends Factory
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
            'user_id' => User::factory(),
            'domain_id' => null,
            'expires_at' => now()->addMinutes(LiveTail::LIFETIME_MINUTES),
            'last_seen_at' => now(),
        ];
    }

    /**
     * A tail whose ten minutes are up.
     */
    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }

    /**
     * A tail whose page stopped checking in (closed, or in a background tab).
     */
    public function idle(): static
    {
        return $this->state(fn () => ['last_seen_at' => now()->subSeconds(LiveTail::IDLE_SECONDS + 5)]);
    }
}
