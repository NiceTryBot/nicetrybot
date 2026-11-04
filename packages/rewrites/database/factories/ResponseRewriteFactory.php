<?php

namespace NiceTry\Rewrites\Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use NiceTry\Rewrites\Enums\RewriteContent;
use NiceTry\Rewrites\Models\ResponseRewrite;

/**
 * @extends Factory<ResponseRewrite>
 */
class ResponseRewriteFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<ResponseRewrite>
     */
    protected $model = ResponseRewrite::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'domain_id' => null,
            'find' => fake()->word(),
            'replace' => fake()->word(),
            'ignore_case' => false,
            'content' => RewriteContent::Html,
            'in_headers' => false,
            'enabled' => true,
        ];
    }

    /**
     * Indicate that the rewrite is switched off.
     */
    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }
}
