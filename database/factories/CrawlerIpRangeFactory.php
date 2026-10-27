<?php

namespace Database\Factories;

use App\Enums\VerifiedCrawler;
use App\Models\CrawlerIpRange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrawlerIpRange>
 */
class CrawlerIpRangeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'crawler' => VerifiedCrawler::OpenAiGptBot,
            'cidr' => fake()->ipv4().'/32',
        ];
    }
}
