<?php

namespace Database\Factories;

use App\Models\UserAgent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserAgent>
 */
class UserAgentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $value = fake()->unique()->userAgent();

        return [
            'hash' => md5($value),
            'value' => $value,
        ];
    }
}
