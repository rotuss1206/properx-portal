<?php

namespace Tests\Feature\Nova;

use App\Enums\AgencyRole;
use App\Enums\AgencyStatus;
use App\Enums\AgencyTier;
use App\Enums\MemberType;
use App\Enums\RateType;
use App\Enums\Role;
use App\Models\Agency;
use App\Models\PartnerCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * Nova staff tools for agencies and partner codes.
 */
class AgencyResourceTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function agencyPayload(User $principal, array $overrides = []): array
    {
        return array_merge([
            'legal_name' => 'Lone Star Benefits LLC',
            'agency_type' => 'health_and_life',
            'tier' => AgencyTier::Agency->value,
            'rate_type' => RateType::Founding->value,
            'status' => AgencyStatus::Pending->value,
            'principal' => $principal->id,
            'agency_npn' => '1234567',
            'fein' => '12-3456789',
            'legal_address' => '100 Congress Ave, Austin, TX 78701',
            'mailing_same_as_legal' => true,
            'phone' => '(512) 555-0100',
            'billing_email' => 'billing@lonestar.test',
        ], $overrides);
    }

    public function test_staff_list_agencies_with_tier_rate_headcount_and_status(): void
    {
        $agency = $this->agency(people: 4, attributes: ['tier' => AgencyTier::Agency, 'status' => AgencyStatus::Active]);

        $row = collect($this->actingAs($this->staff(Role::MembershipCoordinator))
            ->getJson('/nova-api/agencies')
            ->assertOk()
            ->json('resources.0.fields'))
            ->pluck('value', 'attribute');

        $this->assertSame($agency->legal_name, $row['legal_name']);
        $this->assertSame('agency_2to9', $row['tier']);
        $this->assertSame('founding', $row['rate_type']);
        $this->assertSame(4, $row['headcount']);
        $this->assertSame('active', $row['status']);
        $this->assertSame('$159 / person', $row['computed_rate'] ?? collect($row)->first(fn ($value) => is_string($value) && str_contains($value, '/ person')));
    }

    public function test_agencies_can_be_filtered_by_tier_and_status(): void
    {
        $this->agency(attributes: ['tier' => AgencyTier::Agency, 'status' => AgencyStatus::Active]);
        $plus = $this->agency(attributes: ['tier' => AgencyTier::AgencyPlus, 'status' => AgencyStatus::PastDue]);

        $this->actingAs($this->staff(Role::ExecutiveDirector));

        $filters = collect($this->getJson('/nova-api/agencies/filters')->assertOk()->json());
        $this->assertTrue($filters->contains('name', 'Tier'));
        $this->assertTrue($filters->contains('name', 'Status'));

        $tierFilter = $filters->firstWhere('name', 'Tier')['class'];
        $encoded = base64_encode(json_encode([[$tierFilter => AgencyTier::AgencyPlus->value]]));

        $ids = collect($this->getJson('/nova-api/agencies?filters='.$encoded)->assertOk()->json('resources'))->pluck('id.value');
        $this->assertSame([$plus->id], $ids->all());
    }

    public function test_staff_create_an_agency_and_its_principal_is_set_up(): void
    {
        $principal = $this->member();

        $this->actingAs($coordinator = $this->staff(Role::MembershipCoordinator))
            ->postJson('/nova-api/agencies', $this->agencyPayload($principal))
            ->assertCreated();

        $agency = Agency::query()->sole();
        $this->assertSame('12-3456789', $agency->fein);
        $this->assertNull($agency->mailing_address);
        $this->assertTrue($principal->fresh()->hasRole(Role::AgencyPrincipal->value));
        $this->assertSame(AgencyRole::Principal, $principal->memberProfile->fresh()->agency_role);
        $this->assertSame(MemberType::AgencyMember, $principal->memberProfile->fresh()->member_type);
        $this->assertSame(1, $agency->fresh()->headcount);
        $this->assertDatabaseHas('audit_logs', ['action' => 'agency_created', 'resource_id' => $agency->id, 'user_id' => $coordinator->id]);
    }

    public function test_validation_blocks_duplicates_and_po_boxes(): void
    {
        $existing = $this->agency(attributes: ['fein' => '98-7654321', 'agency_npn' => '7654321']);

        $this->actingAs($this->staff(Role::ExecutiveDirector))
            ->postJson('/nova-api/agencies', $this->agencyPayload($this->member(), [
                'fein' => '987654321',
                'agency_npn' => '7654321',
                'legal_address' => 'P.O. Box 12, Austin, TX 78701',
                'mailing_same_as_legal' => false,
                'mailing_address' => '',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fein', 'agency_npn', 'legal_address', 'mailing_address']);

        // Saving the existing agency unchanged is not a duplicate of itself.
        $this->putJson("/nova-api/agencies/{$existing->id}", $this->agencyPayload($existing->principal, [
            'fein' => '98-7654321',
            'agency_npn' => '7654321',
        ]))->assertOk();
    }

    public function test_principal_options_exclude_people_on_other_agencies(): void
    {
        $other = $this->agency(people: 2);
        $free = $this->member();

        $ids = collect($this->actingAs($this->staff(Role::MembershipCoordinator))
            ->getJson('/nova-api/agencies/associatable/principal?search=&withTrashed=false')
            ->assertOk()
            ->json('resources'))
            ->pluck('value');

        $this->assertContains($free->id, $ids);
        $this->assertNotContains($other->principal_user_id, $ids);
        $this->assertEmpty(array_intersect($ids->all(), $other->members()->pluck('user_id')->all()));
    }

    public function test_staff_reassign_the_principal(): void
    {
        $agency = $this->agency(people: 2);
        $previous = $agency->principal;
        $next = $agency->members()->where('user_id', '!=', $previous->id)->first()->user;

        $this->actingAs($this->staff(Role::MembershipCoordinator))
            ->putJson("/nova-api/agencies/{$agency->id}", $this->agencyPayload($next, ['fein' => $agency->fein, 'agency_npn' => $agency->agency_npn]))
            ->assertOk();

        $this->assertTrue($next->fresh()->hasRole(Role::AgencyPrincipal->value));
        $this->assertFalse($previous->fresh()->hasRole(Role::AgencyPrincipal->value));
        $this->assertDatabaseHas('audit_logs', ['action' => 'agency_principal_changed', 'resource_id' => $agency->id]);
    }

    public function test_agency_detail_lists_its_people(): void
    {
        $agency = $this->agency(people: 3);
        $this->agency(people: 2);

        $this->actingAs($this->staff(Role::MembershipCoordinator));
        $this->getJson("/nova-api/agencies/{$agency->id}")->assertOk();

        $ids = collect($this->getJson("/nova-api/member-profiles?viaResource=agencies&viaResourceId={$agency->id}&viaRelationship=members")
            ->assertOk()
            ->json('resources'))
            ->pluck('id.value');

        $this->assertEqualsCanonicalizing($agency->members()->pluck('id')->all(), $ids->all());
    }

    public function test_search_finds_an_agency_by_exact_fein(): void
    {
        $agency = $this->agency(attributes: ['fein' => '55-1234567']);
        $this->agency();

        $ids = collect($this->actingAs($this->staff(Role::ExecutiveDirector))
            ->getJson('/nova-api/agencies?search=55-1234567')
            ->assertOk()
            ->json('resources'))
            ->pluck('id.value');

        $this->assertSame([$agency->id], $ids->all());
    }

    public function test_principal_and_other_roles_cannot_open_agency_screens(): void
    {
        $agency = $this->agency();

        foreach ([$agency->principal, $this->staff(Role::ComplianceSpecialist), $this->member()] as $user) {
            $this->actingAs($user);

            $this->getJson('/nova-api/agencies')->assertForbidden();
            $this->getJson("/nova-api/agencies/{$agency->id}")->assertForbidden();
            $this->putJson("/nova-api/agencies/{$agency->id}", ['legal_name' => 'Changed'])->assertForbidden();
            $this->getJson('/nova-api/partner-codes')->assertForbidden();
        }

        $this->assertNotSame('Changed', $agency->fresh()->legal_name);
    }

    public function test_staff_move_people_between_agencies_but_not_the_principal(): void
    {
        $agency = $this->agency(people: 2);
        $person = $this->member()->memberProfile;

        $this->actingAs($this->staff(Role::MembershipCoordinator));

        $this->putJson("/nova-api/member-profiles/{$person->id}", [
            'first_name' => $person->first_name,
            'last_name' => $person->last_name,
            'agency' => $agency->id,
            'person_type' => 'agency_employee',
            'status' => 'active',
        ])->assertOk();

        $this->assertSame($agency->id, $person->fresh()->agency_id);
        $this->assertSame(3, $agency->fresh()->headcount);
        $this->assertDatabaseHas('audit_logs', ['action' => 'agency_member_added', 'resource_id' => $person->id]);

        // The agency field is read-only for the principal: the submitted value is ignored.
        $principalProfile = $agency->principal->memberProfile;
        $this->putJson("/nova-api/member-profiles/{$principalProfile->id}", [
            'first_name' => $principalProfile->first_name,
            'last_name' => $principalProfile->last_name,
            'agency' => '',
            'status' => 'active',
        ])->assertOk();

        $this->assertSame($agency->id, $principalProfile->fresh()->agency_id);
    }

    public function test_members_cannot_change_their_agency_fields(): void
    {
        $agency = $this->agency(people: 2);
        $member = $agency->members()->where('agency_role', AgencyRole::Member)->first()->user;
        $profile = $member->memberProfile;

        $this->actingAs($member)
            ->putJson("/nova-api/member-profiles/{$profile->id}", [
                'first_name' => $profile->first_name,
                'last_name' => $profile->last_name,
                'agency' => '',
                'person_type' => 'agency_employee',
                'status' => 'inactive',
            ])
            ->assertOk();

        $profile->refresh();
        $this->assertSame($agency->id, $profile->agency_id);
        $this->assertSame('licensed_producer', $profile->person_type->value);
        $this->assertSame('active', $profile->status->value);
    }

    public function test_staff_manage_partner_codes(): void
    {
        $this->actingAs($this->staff(Role::MembershipCoordinator))
            ->postJson('/nova-api/partner-codes', [
                'code' => 'sunbelt-mga',
                'partner_name' => 'Sunbelt Underwriters MGA',
                'active' => true,
            ])
            ->assertCreated();

        $code = PartnerCode::query()->sole();
        $this->assertSame('SUNBELT-MGA', $code->code);
        $this->assertDatabaseHas('audit_logs', ['action' => 'partner_code_created', 'resource_id' => $code->id]);

        $this->postJson('/nova-api/partner-codes', ['code' => 'SUNBELT-MGA', 'partner_name' => 'Duplicate'])
            ->assertJsonValidationErrors(['code']);

        // A code agencies enrolled with is kept for the record.
        $this->agency(attributes: ['partner_code_id' => $code->id]);
        $this->deleteJson('/nova-api/partner-codes?resources[]='.$code->id)->assertOk();
        $this->assertModelExists($code);
    }
}
