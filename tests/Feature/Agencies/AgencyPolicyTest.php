<?php

namespace Tests\Feature\Agencies;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\PartnerCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

class AgencyPolicyTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    public function test_executive_director_and_membership_coordinator_manage_agencies(): void
    {
        $agency = $this->agency();
        $director = $this->staff(Role::ExecutiveDirector);
        $coordinator = $this->staff(Role::MembershipCoordinator);

        foreach ([$director, $coordinator] as $staff) {
            $this->assertTrue($staff->can('viewAny', Agency::class));
            $this->assertTrue($staff->can('view', $agency));
            $this->assertTrue($staff->can('create', Agency::class));
            $this->assertTrue($staff->can('update', $agency));
            $this->assertTrue($staff->can('reassignPrincipal', $agency));
            $this->assertTrue($staff->can('manageRoster', $agency));
        }

        $this->assertTrue($director->can('delete', $agency));
        $this->assertFalse($coordinator->can('delete', $agency));
    }

    public function test_compliance_specialist_and_members_have_no_agency_access(): void
    {
        $agency = $this->agency();

        foreach ([$this->staff(Role::ComplianceSpecialist), $this->member()] as $user) {
            $this->assertFalse($user->can('viewAny', Agency::class));
            $this->assertFalse($user->can('view', $agency));
            $this->assertFalse($user->can('update', $agency));
            $this->assertFalse($user->can('viewAny', PartnerCode::class));
        }
    }

    public function test_principal_sees_and_manages_only_their_own_agency(): void
    {
        $agency = $this->agency();
        $other = $this->agency();
        $principal = $agency->principal;

        $this->assertTrue($principal->can('view', $agency));
        $this->assertTrue($principal->can('update', $agency));
        $this->assertTrue($principal->can('viewRoster', $agency));
        $this->assertTrue($principal->can('manageRoster', $agency));

        $this->assertFalse($principal->can('view', $other));
        $this->assertFalse($principal->can('update', $other));
        $this->assertFalse($principal->can('viewAny', Agency::class));
        $this->assertFalse($principal->can('create', Agency::class));
        $this->assertFalse($principal->can('delete', $agency));
        $this->assertFalse($principal->can('reassignPrincipal', $agency)); // staff only
    }

    public function test_agency_members_who_are_not_the_principal_have_no_access(): void
    {
        $agency = $this->agency(people: 2);
        $member = $agency->members()->where('user_id', '!=', $agency->principal_user_id)->first()->user;

        $this->assertFalse($member->can('view', $agency));
        $this->assertFalse($member->can('update', $agency));
    }

    public function test_replaced_principal_loses_access(): void
    {
        $agency = $this->agency(people: 2);
        $previous = $agency->principal;
        $agency->update(['principal_user_id' => $agency->members()->where('user_id', '!=', $previous->id)->value('user_id')]);

        $this->assertFalse($previous->fresh()->can('view', $agency->fresh()));
    }

    public function test_partner_codes_are_managed_by_staff_and_kept_once_used(): void
    {
        $unused = PartnerCode::factory()->create();
        $used = PartnerCode::factory()->create();
        $this->agency(attributes: ['partner_code_id' => $used->id]);

        foreach ([$this->staff(Role::ExecutiveDirector), $this->staff(Role::MembershipCoordinator)] as $staff) {
            $this->assertTrue($staff->can('viewAny', PartnerCode::class));
            $this->assertTrue($staff->can('create', PartnerCode::class));
            $this->assertTrue($staff->can('update', $used));
            $this->assertTrue($staff->can('delete', $unused));
            $this->assertFalse($staff->can('delete', $used));
        }
    }
}
