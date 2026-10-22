<?php

namespace Database\Seeders;

use App\Enums\AgencyStatus;
use App\Enums\AgencyTier;
use App\Enums\InviteStatus;
use App\Enums\RateType;
use App\Enums\Role;
use App\Models\Agency;
use App\Models\MemberProfile;
use App\Models\PartnerCode;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Partner codes and three agencies of 5, 12 and 55 people (one per tier). Principals sign in
 * as principal01@ … principal03@properx.test, their people as agency01.person02@properx.test etc.
 *
 * Model events are muted while seeding, so roster side effects are applied explicitly.
 */
class AgencySeeder extends Seeder
{
    public function run(): void
    {
        $codes = $this->partnerCodes();

        $agencies = [
            // [headcount, partner code, status]
            [5, $codes['SUNBELT-MGA'], AgencyStatus::Active],
            [12, null, AgencyStatus::PastDue],
            [55, $codes['HERITAGE-FMO'], AgencyStatus::Pending],
        ];

        foreach ($agencies as $index => [$headcount, $partnerCode, $status]) {
            $this->createAgency($index + 1, $headcount, $partnerCode, $status);
        }
    }

    /**
     * @return array<string, PartnerCode>
     */
    private function partnerCodes(): array
    {
        $codes = [
            'SUNBELT-MGA' => ['Sunbelt Underwriters MGA', true, null],
            'HERITAGE-FMO' => ['Heritage Financial FMO', true, now()->addYear()],
            'COASTAL-FMO' => ['Coastal Marketing FMO', false, null],
            'LEGACY-MGA' => ['Legacy Brokerage MGA', true, now()->subMonth()],
        ];

        return collect($codes)->map(fn (array $code, string $value) => PartnerCode::factory()->create([
            'code' => $value,
            'partner_name' => $code[0],
            'active' => $code[1],
            'expires_at' => $code[2],
        ]))->all();
    }

    private function createAgency(int $number, int $headcount, ?PartnerCode $partnerCode, AgencyStatus $status): void
    {
        $billed = $status !== AgencyStatus::Pending;

        $agency = Agency::factory()->create([
            'tier' => AgencyTier::forHeadcount($headcount),
            'rate_type' => RateType::Founding,
            'partner_code_id' => $partnerCode?->getKey(),
            'status' => $status,
            'stripe_customer_id' => $billed ? sprintf('cus_agency%06d', $number) : null,
            'stripe_subscription_id' => $billed ? sprintf('sub_agency%06d', $number) : null,
        ]);

        $principal = $this->createPerson($agency, sprintf('principal%02d@properx.test', $number), employee: false, inviteStatus: InviteStatus::AccountActive);

        for ($person = 2; $person <= $headcount; $person++) {
            $this->createPerson(
                $agency,
                sprintf('agency%02d.person%02d@properx.test', $number, $person),
                employee: $person % 4 === 0,
                inviteStatus: fake()->boolean(60) ? InviteStatus::AccountActive : InviteStatus::Invited,
            );
        }

        $agency->forceFill(['principal_user_id' => $principal->getKey()])->saveQuietly();
        $agency->syncPrincipal();
        $agency->syncHeadcount();
    }

    private function createPerson(Agency $agency, string $email, bool $employee, InviteStatus $inviteStatus): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->assignRole(Role::Member->value);

        $factory = MemberProfile::factory()->inAgency($agency, $inviteStatus)->for($user);
        $profile = ($employee ? $factory->employee() : $factory)->create([
            'firm_name' => $agency->legal_name,
        ]);

        $user->update(['name' => $profile->fullName()]);

        return $user;
    }
}
