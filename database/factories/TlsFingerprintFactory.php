<?php

namespace Database\Factories;

use App\Enums\TlsFingerprintType;
use App\Models\TlsFingerprint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TlsFingerprint>
 */
class TlsFingerprintFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => TlsFingerprintType::Ja4,
            'value' => 't13d1518h2_'.fake()->unique()->regexify('[0-9a-f]{12}').'_'.fake()->regexify('[0-9a-f]{12}'),
        ];
    }
}
