<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\VisitorErasure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VisitorErasure>
 */
class VisitorErasureFactory extends Factory
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
            'user_id' => null,
            'subject_hash' => VisitorErasure::hashSubject(fake()->ipv4(), null),
            'subject_kind' => 'ip',
            'deleted' => null,
            'completed_at' => null,
        ];
    }
}
