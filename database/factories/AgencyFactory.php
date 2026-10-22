<?php

namespace Database\Factories;

use App\Enums\AgencyStatus;
use App\Enums\AgencyTier;
use App\Enums\AgencyType;
use App\Enums\RateType;
use App\Models\Agency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agency>
 */
class AgencyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'legal_name' => fake()->unique()->lastName().' '.fake()->randomElement(['Insurance Agency', 'Insurance Group', 'Benefits LLC', 'Agency Inc.']),
            'dba' => fake()->optional(0.3)->company(),
            'agency_type' => fake()->randomElement(AgencyType::cases()),
            'agency_npn' => fake()->optional(0.5)->numerify('##########'),
            'fein' => fake()->unique()->numerify('##-#######'),
            'legal_address' => fake()->streetAddress().', '.fake()->city().', '.fake()->stateAbbr().' '.fake()->postcode(),
            'mailing_same_as_legal' => true,
            'phone' => fake()->numerify('(###) ###-####'),
            'website' => fake()->optional(0.7)->url(),
            'billing_email' => fake()->unique()->companyEmail(),
            'referral_source' => fake()->randomElement(array_keys(Agency::REFERRAL_SOURCES)),
            'tier' => AgencyTier::Agency,
            'rate_type' => RateType::Founding,
            'status' => AgencyStatus::Active,
        ];
    }

    public function withMailingAddress(): static
    {
        return $this->state(fn () => [
            'mailing_same_as_legal' => false,
            'mailing_address' => 'PO Box '.fake()->numberBetween(100, 9999).', '.fake()->city().', '.fake()->stateAbbr().' '.fake()->postcode(),
        ]);
    }
}
