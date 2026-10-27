<?php

namespace Database\Factories;

use App\Enums\AiProvider;
use App\Models\AiCredential;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiCredential>
 */
class AiCredentialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = 'sk-ant-api03-'.Str::random(80);

        return [
            'user_id' => User::factory(),
            'provider' => AiProvider::Anthropic,
            'secret' => $key,
            'hint' => AiCredential::hintFor($key),
        ];
    }
}
