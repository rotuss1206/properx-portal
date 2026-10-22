<?php

namespace Database\Factories;

use App\Models\PartnerCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PartnerCode>
 */
class PartnerCodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('???-####'),
            'partner_name' => fake()->company().' '.fake()->randomElement(['MGA', 'FMO']),
            'active' => true,
            'expires_at' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }
}
