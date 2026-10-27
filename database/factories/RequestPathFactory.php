<?php

namespace Database\Factories;

use App\Enums\PathCategory;
use App\Models\RequestPath;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RequestPath>
 */
class RequestPathFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $path = '/'.fake()->unique()->slug(2);

        return [
            'hash' => md5($path),
            'path' => $path,
            'category' => PathCategory::classify($path),
        ];
    }
}
