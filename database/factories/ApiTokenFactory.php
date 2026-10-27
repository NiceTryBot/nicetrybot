<?php

namespace Database\Factories;

use App\Enums\ApiAbility;
use App\Models\ApiToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApiToken>
 */
class ApiTokenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'team_id' => Team::factory(),
            'name' => fake()->words(2, true),
            'token_hash' => hash('sha256', ApiToken::PREFIX.Str::random(ApiToken::SECRET_LENGTH)),
            'abilities' => [ApiAbility::Read->value],
        ];
    }

    /**
     * A token that may do everything.
     */
    public function withEveryAbility(): static
    {
        return $this->state(fn () => ['abilities' => array_map(fn (ApiAbility $ability) => $ability->value, ApiAbility::cases())]);
    }

    /**
     * A token that has run out.
     */
    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }
}
