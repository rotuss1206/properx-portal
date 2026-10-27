<?php

namespace Database\Seeders;

use App\Enums\AgencyStatus;
use App\Enums\AgencyTier;
use App\Enums\AgencyType;
use App\Enums\InviteStatus;
use App\Enums\PersonType;
use App\Enums\RateType;
use App\Enums\Role;
use App\Models\Agency;
use App\Models\PartnerCode;
use App\Models\User;
use Database\Seeders\Support\DemoPeople;
use Illuminate\Database\Seeder;

/**
 * Partner codes and three fictional agencies of 5, 12 and 55 people (one per tier). Principals
 * are principal01@ … principal03@properx.test, their people agency01.person02@properx.test etc.
 * Fixed data, no Faker; idempotent.
 *
 * Model events are muted while seeding, so roster side effects are applied explicitly.
 */
class AgencySeeder extends Seeder
{
    /** @var array<string, array{string, bool, ?int}> code => partner, active, expires in days (negative: expired) */
    private const PARTNER_CODES = [
        'SUNBELT-MGA' => ['Sunbelt Underwriters MGA', true, null],
        'HERITAGE-FMO' => ['Heritage Financial FMO', true, 365],
        'COASTAL-FMO' => ['Coastal Marketing FMO', false, null],
        'LEGACY-MGA' => ['Legacy Brokerage MGA', true, -30],
    ];

    /**
     * @var list<array{legal_name: string, dba: ?string, type: AgencyType, npn: ?string, fein: string, domain: string, headcount: int, partner: ?string, status: AgencyStatus, referral: string}>
     */
    private const AGENCIES = [
        ['legal_name' => 'Lone Star Benefits LLC', 'dba' => null, 'type' => AgencyType::HealthLife, 'npn' => '1900000101',
            'fein' => '74-1000101', 'domain' => 'lonestarbenefits.example', 'headcount' => 5, 'partner' => 'SUNBELT-MGA',
            'status' => AgencyStatus::Active, 'referral' => 'partner'],
        ['legal_name' => 'Gulf Coast Insurance Group Inc.', 'dba' => 'Gulf Coast Insurance', 'type' => AgencyType::MultiLine, 'npn' => null,
            'fein' => '76-1000202', 'domain' => 'gulfcoastinsurance.example', 'headcount' => 12, 'partner' => null,
            'status' => AgencyStatus::PastDue, 'referral' => 'mailer'],
        ['legal_name' => 'Prairie Mutual Agency Inc.', 'dba' => null, 'type' => AgencyType::PropertyCasualty, 'npn' => '1900000303',
            'fein' => '75-1000303', 'domain' => 'prairiemutual.example', 'headcount' => 55, 'partner' => 'HERITAGE-FMO',
            'status' => AgencyStatus::Pending, 'referral' => 'linkedin'],
    ];

    public function run(): void
    {
        $codes = $this->partnerCodes();

        foreach (self::AGENCIES as $index => $definition) {
            $this->agency($index + 1, $definition, $definition['partner'] === null ? null : $codes[$definition['partner']]);
        }
    }

    /**
     * @return array<string, PartnerCode>
     */
    private function partnerCodes(): array
    {
        $codes = [];

        foreach (self::PARTNER_CODES as $code => [$partner, $active, $expiresInDays]) {
            $codes[$code] = PartnerCode::query()->firstOrCreate(['code' => $code], [
                'partner_name' => $partner,
                'active' => $active,
                'expires_at' => $expiresInDays === null ? null : now()->addDays($expiresInDays),
            ]);
        }

        return $codes;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function agency(int $number, array $definition, ?PartnerCode $partnerCode): void
    {
        $billed = $definition['status'] !== AgencyStatus::Pending;

        $agency = Agency::query()->firstOrNew(['legal_name' => $definition['legal_name']]);

        if (! $agency->exists) {
            $agency->forceFill([
                'dba' => $definition['dba'],
                'agency_type' => $definition['type'],
                'agency_npn' => $definition['npn'],
                'fein' => $definition['fein'],
                'legal_address' => DemoPeople::address($number * 11),
                'mailing_same_as_legal' => true,
                'phone' => DemoPeople::phone($number * 11),
                'website' => 'https://www.'.$definition['domain'],
                'billing_email' => 'billing@'.$definition['domain'],
                'referral_source' => $definition['referral'],
                'tier' => AgencyTier::forHeadcount($definition['headcount']),
                'rate_type' => RateType::Founding,
                'partner_code_id' => $partnerCode?->getKey(),
                'status' => $definition['status'],
                'stripe_customer_id' => $billed ? sprintf('cus_agency%06d', $number) : null,
                'stripe_subscription_id' => $billed ? sprintf('sub_agency%06d', $number) : null,
            ])->save();
        }

        $principal = $this->person($agency, $number, 1, sprintf('principal%02d@properx.test', $number));

        for ($person = 2; $person <= $definition['headcount']; $person++) {
            $this->person($agency, $number, $person, sprintf('agency%02d.person%02d@properx.test', $number, $person));
        }

        $agency->forceFill(['principal_user_id' => $principal->getKey()])->saveQuietly();
        $agency->syncPrincipal();
        $agency->syncHeadcount();
    }

    /**
     * Person 1 is the principal; every fourth person is an employee (no NPN or license).
     */
    private function person(Agency $agency, int $agencyNumber, int $person, string $email): User
    {
        $index = 100 * $agencyNumber + $person;
        [$first, $last] = DemoPeople::name($index);
        [$user] = DemoPeople::user($email, "{$first} {$last}", Role::Member);

        $employee = $person % 4 === 0;
        $license = $employee ? [] : [
            'npn' => sprintf('%08d', 52_000_000 + $index * 613),
            'license_number' => sprintf('%s-%07d', DemoPeople::state($index), 7_200_000 + $index * 97),
            'license_state' => DemoPeople::state($index),
            'license_type' => 'Life & Health',
            'license_expires_at' => today()->addDays(120 + $index % 400),
        ];

        DemoPeople::profile($user, $license + [
            'first_name' => $first,
            'last_name' => $last,
            'phone' => DemoPeople::phone($index),
            'address' => DemoPeople::address($index),
            'firm_name' => $agency->legal_name,
            'agency_id' => $agency->getKey(),
            'person_type' => $employee ? PersonType::AgencyEmployee : PersonType::LicensedProducer,
            'invite_status' => $person === 1 || $person % 5 < 3 ? InviteStatus::AccountActive : InviteStatus::Invited,
        ]);

        DemoPeople::notificationSettings($user);

        return $user;
    }
}
