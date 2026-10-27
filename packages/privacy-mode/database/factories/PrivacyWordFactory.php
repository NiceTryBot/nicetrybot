<?php

namespace NiceTry\PrivacyMode\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use NiceTry\PrivacyMode\Models\PrivacyWord;

/**
 * @extends Factory<PrivacyWord>
 */
class PrivacyWordFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<PrivacyWord>
     */
    protected $model = PrivacyWord::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'word' => strtolower(fake()->unique()->lexify('??????')),
            'replacement' => null,
        ];
    }
}
