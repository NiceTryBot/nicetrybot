<?php

namespace Database\Factories;

use App\Enums\RuleAction;
use App\Models\EdgeRule;
use App\Models\Team;
use App\Models\Verdict;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Verdict>
 */
class VerdictFactory extends Factory
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
            'edge_rule_id' => fn (array $attributes) => EdgeRule::factory()->state(['team_id' => $attributes['team_id']]),
            'action' => RuleAction::Ban,
            'client_ip' => '203.0.113.'.fake()->numberBetween(1, 254),
            'ja4_id' => null,
            'matched_requests' => 1,
            'first_matched_at' => now()->subMinutes(5),
            'last_matched_at' => now()->subMinutes(5),
            'expires_at' => now()->addDays(30),
        ];
    }

    /**
     * Indicate that the verdict has run out.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => ['expires_at' => now()->subMinute()]);
    }
}
