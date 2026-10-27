<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\VisitorDataSetting;
use App\Support\VisitorDataHolders;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VisitorDataSetting>
 */
class VisitorDataSettingFactory extends Factory
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
            ...app(VisitorDataHolders::class)->defaults(),
            'contact' => null,
        ];
    }
}
