<?php

namespace Tests\Feature\Agencies;

use App\Enums\AgencyRole;
use App\Enums\MemberType;
use App\Enums\PersonType;
use App\Enums\Role;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\MemberProfile;
use App\Models\PartnerCode;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

class AgencyModelTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    public function test_agency_identifiers_and_contact_details_are_encrypted_at_rest(): void
    {
        $agency = Agency::factory()->withMailingAddress()->create(['agency_npn' => '1234567890']);
        $raw = DB::table('agencies')->where('id', $agency->id)->first();

        foreach (['agency_npn', 'fein', 'legal_address', 'mailing_address', 'phone', 'billing_email'] as $column) {
            $this->assertNotEmpty($agency->{$column});
            $this->assertStringNotContainsString($agency->{$column}, $raw->{$column}, "{$column} is stored in plain text");
        }

        $this->assertSame(64, strlen($raw->agency_npn_index));
        $this->assertSame(64, strlen($raw->fein_index));
        $this->assertSame($agency->legal_name, $raw->legal_name); // searchable and sortable
    }

    public function test_member_contact_details_are_encrypted_at_rest(): void
    {
        $profile = $this->member()->memberProfile;
        $raw = DB::table('member_profiles')->where('id', $profile->id)->first();

        $this->assertStringNotContainsString($profile->phone, $raw->phone);
        $this->assertStringNotContainsString($profile->address, $raw->address);
    }

    public function test_agency_npn_and_fein_are_found_by_blind_index_and_unique(): void
    {
        $agency = Agency::factory()->create(['agency_npn' => '1234567890', 'fein' => '12-3456789']);

        $this->assertTrue($agency->is(Agency::query()->whereBlindIndex('agency_npn', '1234567890')->first()));
        $this->assertTrue($agency->is(Agency::query()->whereBlindIndex('fein', ' 12-3456789 ')->first()));
        $this->assertTrue($agency->is(Agency::query()->whereBlindIndex('fein', '123456789')->first()));

        $this->expectException(QueryException::class);
        Agency::factory()->create(['fein' => '12-3456789']);
    }

    public function test_mailing_address_defaults_to_the_business_address(): void
    {
        $agency = Agency::factory()->create(['mailing_address' => 'PO Box 1']);

        $this->assertNull($agency->mailing_address);
        $this->assertSame($agency->legal_address, $agency->mailingAddress());

        $agency->update(['mailing_same_as_legal' => false, 'mailing_address' => 'PO Box 2, Austin, TX 73301']);
        $this->assertSame('PO Box 2, Austin, TX 73301', $agency->fresh()->mailingAddress());
    }

    public function test_principal_is_put_on_the_roster_and_gets_the_principal_role(): void
    {
        $agency = $this->agency(people: 3);
        $principal = $agency->principal;

        $this->assertTrue($principal->hasRole(Role::AgencyPrincipal->value));
        $this->assertTrue($principal->hasRole(Role::Member->value)); // layered on Member
        $this->assertTrue($principal->memberProfile->isAgencyPrincipal());
        $this->assertSame(3, $agency->headcount);
        $this->assertSame(1, $agency->members()->where('agency_role', AgencyRole::Principal)->count());
        $this->assertTrue($agency->is($principal->ledAgency));
    }

    public function test_reassigning_the_principal_moves_the_role(): void
    {
        $agency = $this->agency(people: 3);
        $previous = $agency->principal;
        $next = $agency->members()->where('agency_role', AgencyRole::Member)->first()->user;

        $agency->update(['principal_user_id' => $next->id]);

        $this->assertFalse($previous->fresh()->hasRole(Role::AgencyPrincipal->value));
        $this->assertSame(AgencyRole::Member, $previous->memberProfile->fresh()->agency_role);
        $this->assertSame($agency->id, $previous->memberProfile->fresh()->agency_id); // stays on the roster
        $this->assertTrue($next->fresh()->hasRole(Role::AgencyPrincipal->value));
        $this->assertTrue($next->memberProfile->fresh()->isAgencyPrincipal());
        $this->assertSame(3, $agency->fresh()->headcount);
        $this->assertDatabaseHas('audit_logs', ['action' => 'agency_principal_changed', 'resource_id' => $agency->id]);
    }

    public function test_a_principal_cannot_leave_until_the_role_is_reassigned(): void
    {
        $agency = $this->agency();

        $this->expectException(LogicException::class);

        $agency->principal->memberProfile->update(['agency_id' => null]);
    }

    public function test_member_type_and_headcount_follow_the_roster(): void
    {
        $agency = $this->agency(people: 2);
        $other = $this->agency(people: 2);
        $profile = $this->member()->memberProfile;

        $this->assertSame(MemberType::Individual, $profile->member_type);
        $this->assertNull($profile->agency_role);

        $profile->update(['agency_id' => $agency->id]);
        $this->assertSame(MemberType::AgencyMember, $profile->member_type);
        $this->assertSame(AgencyRole::Member, $profile->agency_role);
        $this->assertSame(PersonType::LicensedProducer, $profile->person_type);
        $this->assertSame(3, $agency->fresh()->headcount);

        $profile->update(['agency_id' => $other->id]);
        $this->assertSame(2, $agency->fresh()->headcount);
        $this->assertSame(3, $other->fresh()->headcount);

        $profile->update(['agency_id' => null]);
        $this->assertSame(MemberType::Individual, $profile->fresh()->member_type);
        $this->assertSame(2, $other->fresh()->headcount);

        $profile->update(['agency_id' => $other->id]);
        $profile->delete();
        $this->assertSame(2, $other->fresh()->headcount);
    }

    public function test_employees_have_no_npn(): void
    {
        $agency = $this->agency();
        $employee = MemberProfile::factory()->inAgency($agency)->employee()->create();

        $this->assertSame(PersonType::AgencyEmployee, $employee->person_type);
        $this->assertNull($employee->npn);
        $this->assertSame(3, $agency->fresh()->headcount);
    }

    public function test_deleting_an_agency_keeps_its_people_as_individual_members(): void
    {
        $agency = $this->agency(people: 2);
        $principal = $agency->principal;

        $agency->delete();

        $this->assertFalse($principal->fresh()->hasRole(Role::AgencyPrincipal->value));
        $this->assertSame(0, MemberProfile::query()->whereNotNull('agency_id')->count());
        $this->assertSame(MemberType::Individual, $principal->memberProfile->fresh()->member_type);
        $this->assertNull($principal->memberProfile->fresh()->agency_role);
        $this->assertDatabaseHas('audit_logs', ['action' => 'agency_deleted', 'resource_id' => $agency->id]);
    }

    public function test_agency_actions_are_audited(): void
    {
        $this->actingAs($coordinator = $this->staff(Role::MembershipCoordinator));

        $agency = $this->agency(people: 2);
        $joined = $agency->members()->where('agency_role', AgencyRole::Member)->first();

        $this->assertDatabaseHas('audit_logs', ['action' => 'agency_created', 'resource_id' => $agency->id, 'user_id' => $coordinator->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'agency_member_added', 'resource_type' => $joined->getMorphClass(), 'resource_id' => $joined->id]);

        $agency->update(['phone' => '(555) 010-0000']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'agency_updated', 'resource_id' => $agency->id]);

        $joined->update(['agency_id' => null]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'agency_member_removed', 'resource_id' => $joined->id]);

        // Roster moves are not also logged as profile edits; headcount refreshes are not agency edits.
        $this->assertDatabaseMissing('audit_logs', ['action' => 'profile_updated', 'resource_id' => $joined->id]);
        $this->assertSame(1, AuditLog::query()->where('action', 'agency_updated')->count());
    }

    public function test_partner_code_validity(): void
    {
        $valid = PartnerCode::factory()->create(['code' => ' sunbelt-mga ']);
        PartnerCode::factory()->inactive()->create(['code' => 'COASTAL']);
        PartnerCode::factory()->expired()->create(['code' => 'LEGACY']);
        $expiring = PartnerCode::factory()->create(['code' => 'HERITAGE', 'expires_at' => now()->addDay()]);

        $this->assertSame('SUNBELT-MGA', $valid->code);
        $this->assertTrue($valid->is(PartnerCode::findValid('Sunbelt-MGA')));
        $this->assertNull(PartnerCode::findValid('COASTAL'));
        $this->assertNull(PartnerCode::findValid('LEGACY'));
        $this->assertNull(PartnerCode::findValid('UNKNOWN'));
        $this->assertNull(PartnerCode::findValid(''));
        $this->assertTrue($expiring->isValid());
        $this->assertFalse($expiring->isValid(now()->addDays(2)));
    }

    public function test_partner_code_changes_are_audited(): void
    {
        $code = PartnerCode::factory()->create();
        $code->update(['active' => false]);
        $code->delete();

        foreach (['partner_code_created', 'partner_code_updated', 'partner_code_deleted'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action, 'resource_id' => $code->id]);
        }
    }
}
