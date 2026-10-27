<?php

namespace Database\Factories;

use App\Enums\LabelKind;
use App\Models\ClientLabel;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientLabel>
 */
class ClientLabelFactory extends Factory
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
            'kind' => LabelKind::Ip,
            'value' => '203.0.113.7',
            'name' => 'Known scanner',
        ];
    }

    /**
     * A label on the team's own traffic.
     */
    public function mine(): static
    {
        return $this->state(fn (array $attributes) => ['name' => 'Me', 'is_mine' => true]);
    }
}
