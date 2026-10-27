<?php

namespace Database\Factories;

use App\Models\SavedSearch;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedSearch>
 */
class SavedSearchFactory extends Factory
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
            'name' => 'Public form thank-yous',
            'parameters' => ['q' => "path LIKE '/sign/%/thank-you%'", 'period' => '16h'],
        ];
    }
}
