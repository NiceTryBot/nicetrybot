<?php

namespace Database\Factories;

use App\Models\EdgeLogFile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EdgeLogFile>
 */
class EdgeLogFileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'raw/dt=2026-10-03/hh=21/caddy-nicetry-'.Str::uuid().'.ndjson.gz',
            'rows_imported' => fake()->numberBetween(1, 500),
            'rows_skipped' => 0,
            'imported_at' => now(),
        ];
    }
}
