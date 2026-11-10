<?php

namespace NiceTry\WhiteList\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use NiceTry\WhiteList\Models\WhitelistEntry;

/**
 * @extends Factory<WhitelistEntry>
 */
class WhitelistEntryFactory extends Factory
{
    protected $model = WhitelistEntry::class;

    public function definition(): array
    {
        return [
            'name' => ucwords($this->faker->unique()->words(2, true)),
            'category' => $this->faker->randomElement(['bot/crawler', 'bot/ai', 'office/vpn', 'partner/scanner']),
            'ranges' => [$this->faker->unique()->ipv4()],
            'enabled' => true,
            'reason' => null,
            'added_by_id' => null,
        ];
    }

    public function disabled(): static
    {
        return $this->state(['enabled' => false]);
    }

    /**
     * @param  list<string>  $ranges
     */
    public function ranges(array $ranges): static
    {
        return $this->state(['ranges' => $ranges]);
    }
}
