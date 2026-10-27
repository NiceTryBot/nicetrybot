<?php

namespace Database\Factories;

use App\Enums\DomainStatus;
use App\Enums\HostHeaderMode;
use App\Enums\HstsPolicy;
use App\Enums\ProtectionMode;
use App\Models\Domain;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Domain>
 */
class DomainFactory extends Factory
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
            'hostname' => fake()->unique()->domainWord().'.example.com',
            'origin_url' => 'https://origin.'.fake()->domainWord().'.example.net',
            'host_header_mode' => HostHeaderMode::PassThrough,
            'origin_host_header' => null,
            'forwarded_host_header' => null,
            'server_header' => null,
            'origin_timeout_seconds' => 30,
            'hsts_policy' => HstsPolicy::Off,
            'cache_assets' => false,
            'serves_www' => false,
            'protection_mode' => ProtectionMode::Observe,
            'status' => DomainStatus::PendingVerification,
            'verification_token' => Str::random(32),
            'origin_secret' => Str::random(48),
            'verified_at' => null,
        ];
    }

    /**
     * Indicate that the domain is served under www. too.
     */
    public function servingWww(): static
    {
        return $this->state(fn (array $attributes) => [
            'serves_www' => true,
        ]);
    }

    /**
     * Indicate that ownership of the domain has been verified.
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DomainStatus::Active,
            'verified_at' => now()->subDays(14),
        ]);
    }

    /**
     * Indicate that protection for the domain is paused.
     */
    public function paused(): static
    {
        return $this->verified()->state(fn (array $attributes) => [
            'status' => DomainStatus::Paused,
        ]);
    }

    /**
     * Indicate that tagged traffic is diverted or blocked.
     */
    public function enforcing(): static
    {
        return $this->state(fn (array $attributes) => [
            'protection_mode' => ProtectionMode::Enforce,
        ]);
    }
}
