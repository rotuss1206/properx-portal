<?php

namespace Database\Factories;

use App\Enums\AgencyRole;
use App\Enums\InviteStatus;
use App\Enums\LicenseStatus;
use App\Enums\MemberType;
use App\Enums\PersonType;
use App\Models\Agency;
use App\Models\MemberProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<MemberProfile>
 */
class MemberProfileFactory extends Factory
{
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
            'license_type' => fake()->randomElement(MemberProfile::LICENSE_TYPES),
            'license_expires_at' => fake()->dateTimeBetween(today()->addDays(61), today()->addYears(2)),
            // Resolved after states are applied, so it always matches license_expires_at.
            'license_status' => fn (array $attributes) => LicenseStatus::fromExpiryDate(
                $attributes['license_expires_at'] === null ? null : Carbon::parse($attributes['license_expires_at'])
            ),
            // Set here too (not only in the model's "saving" hook): seeders run with model events muted.
            'certificate_code' => fn () => MemberProfile::generateCertificateCode(),
            'member_number' => fn (array $attributes) => MemberProfile::formatMemberNumber($attributes['user_id']),
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'license_expires_at' => fake()->dateTimeBetween(today()->addDays(61), today()->addYears(2)),
        ]);
    }

    /**
     * License expires within the next 60 days.
     */
    public function expiring(): static
    {
        return $this->state(fn () => [
            'license_expires_at' => fake()->dateTimeBetween(today(), today()->addDays(60)),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'license_expires_at' => fake()->dateTimeBetween(today()->subYear(), today()->subDay()),
        ]);
    }

    /**
     * On an agency roster (member type and role follow from the link, see MemberProfile).
     */
    public function inAgency(Agency $agency, InviteStatus $inviteStatus = InviteStatus::Invited): static
    {
        return $this->state(fn () => [
            'agency_id' => $agency->getKey(),
            'member_type' => MemberType::AgencyMember,
            'agency_role' => AgencyRole::Member,
            'person_type' => PersonType::LicensedProducer,
            'invite_status' => $inviteStatus,
        ]);
    }

    /**
     * Employee of a licensed producer: no NPN and no license of their own.
     */
    public function employee(): static
    {
        return $this->state(fn () => [
            'person_type' => PersonType::AgencyEmployee,
            'npn' => null,
            'license_number' => null,
            'license_state' => null,
            'license_type' => null,
            'license_expires_at' => null,
        ]);
    }
}
