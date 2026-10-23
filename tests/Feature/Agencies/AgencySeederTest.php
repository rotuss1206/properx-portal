<?php

namespace Tests\Feature\Agencies;

use App\Enums\AgencyRole;
use App\Enums\AgencyTier;
use App\Enums\MemberType;
use App\Enums\PersonType;
use App\Enums\Role;
use App\Models\Agency;
use App\Models\PartnerCode;
use Database\Seeders\AgencySeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

class AgencySeederTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    public function test_seeds_three_agencies_with_principals_and_partner_codes(): void
    {
        $this->seedRoles();

        // DemoSeeder runs with model events muted.
        Model::withoutEvents(fn () => $this->seed(AgencySeeder::class));

        $agencies = Agency::query()->with(['principal', 'members'])->orderBy('id')->get();

        $this->assertSame([5, 12, 55], $agencies->pluck('headcount')->all());
        $this->assertSame([AgencyTier::Agency, AgencyTier::AgencyPlus, AgencyTier::AgencyEnterprise], $agencies->pluck('tier')->all());

        foreach ($agencies as $index => $agency) {
            $this->assertSame(sprintf('principal%02d@properx.test', $index + 1), $agency->principal->email);
            $this->assertTrue($agency->principal->hasRole(Role::AgencyPrincipal->value));
            $this->assertSame(1, $agency->members->where('agency_role', AgencyRole::Principal)->count());
            $this->assertTrue($agency->members->every(fn ($profile) => $profile->member_type === MemberType::AgencyMember));
            $this->assertTrue($agency->members->where('person_type', PersonType::AgencyEmployee)->every(fn ($profile) => $profile->npn === null));
        }

        $this->assertTrue($agencies[0]->is(Agency::query()->whereBlindIndex('fein', $agencies[0]->fein)->first()));
        $this->assertNotNull(PartnerCode::findValid('SUNBELT-MGA'));
        $this->assertNull(PartnerCode::findValid('COASTAL-FMO'));
        $this->assertNull(PartnerCode::findValid('LEGACY-MGA'));
    }
}
