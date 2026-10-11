<?php

namespace Database\Factories;

use App\Enums\LicenseStatus;
use App\Models\MemberProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<MemberProfile>
 */
class MemberProfileFactory extends Factory
{
    private const LICENSE_TYPES = [
        'Life & Health',
        'Property & Casualty',
        'Life Only',
        'Accident & Health',
        'Personal Lines',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => fake()->numerify('(###) ###-####'),
            'address' => fake()->streetAddress().', '.fake()->city().', '.fake()->stateAbbr().' '.fake()->postcode(),
            'firm_name' => fake()->optional(0.8)->company(),
            'npn' => fake()->unique()->numerify('########'),
            'license_number' => strtoupper(fake()->unique()->bothify('??-#######')),
            'license_state' => fake()->stateAbbr(),
            'license_type' => fake()->randomElement(self::LICENSE_TYPES),
            'license_expires_at' => fake()->dateTimeBetween('+61 days', '+2 years'),
            // Resolved after states are applied, so it always matches license_expires_at.
            'license_status' => fn (array $attributes) => LicenseStatus::fromExpiryDate(
                Carbon::parse($attributes['license_expires_at'])
            ),
            'certificate_code' => fn () => 'PPX-'.strtoupper(Str::random(10)),
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'license_expires_at' => fake()->dateTimeBetween('+61 days', '+2 years'),
        ]);
    }

    /**
     * License expires within the next 60 days.
     */
    public function expiring(): static
    {
        return $this->state(fn () => [
            'license_expires_at' => fake()->dateTimeBetween('today', '+60 days'),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'license_expires_at' => fake()->dateTimeBetween('-1 year', '-1 day'),
        ]);
    }
}
