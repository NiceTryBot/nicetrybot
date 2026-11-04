<?php

namespace NiceTry\DynamicSubdomains\Database\Factories;

use App\Models\Domain;
use Illuminate\Database\Eloquent\Factories\Factory;
use NiceTry\DynamicSubdomains\Models\DynamicSubdomainParent;

/**
 * @extends Factory<DynamicSubdomainParent>
 */
class DynamicSubdomainParentFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<DynamicSubdomainParent>
     */
    protected $model = DynamicSubdomainParent::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'domain_id' => Domain::factory()->verified(),
        ];
    }
}
