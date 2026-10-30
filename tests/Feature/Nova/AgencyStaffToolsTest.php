<?php

namespace Tests\Feature\Nova;

use App\Enums\AgencyRole;
use App\Enums\AgencyTier;
use App\Enums\LicenseStatus;
use App\Enums\RateType;
use App\Enums\Role;
use App\Exceptions\IneligiblePrincipalException;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\MemberProfile;
use App\Models\ReminderLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * Fixes from the manual review of agencies in Nova: roster columns, principal eligibility,
 * read-only pricing with Executive Director overrides, and a readable audit log.
 */
class AgencyStaffToolsTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    /**
     * An agency led by a licensed producer, with one more producer and one employee.
     *
     * @return array{Agency, User, User, User}
     */
    private function agencyWithEmployee(): array
    {
        $agency = $this->agency(people: 2);
        $principal = $agency->principal;
        $producer = $agency->members()->where('agency_role', AgencyRole::Member)->sole()->user;

        $employee = User::factory()->withTwoFactor()->create(['name' => 'Dakota Lawson'])->assignRole(Role::Member->value);
        MemberProfile::factory()->employee()->for($employee)->create(['agency_id' => $agency->id, 'first_name' => 'Dakota', 'last_name' => 'Lawson']);

        return [$agency->refresh(), $principal, $producer, $employee->load('memberProfile')];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function updateAgency(Agency $agency, array $overrides = []): TestResponse
    {
        return $this->putJson("/nova-api/agencies/{$agency->id}", array_merge([
            'legal_name' => $agency->legal_name,
            'agency_type' => $agency->agency_type->value,
            'status' => $agency->status->value,
            'principal' => $agency->principal_user_id,
            'agency_npn' => $agency->agency_npn,
            'fein' => $agency->fein,
            'legal_address' => '100 Congress Ave, Austin, TX 78701',
            'mailing_same_as_legal' => true,
            'phone' => '(512) 555-0100',
            'billing_email' => 'billing@lonestar.test',
        ], $overrides));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rosterRows(Agency $agency): array
    {
        return collect($this->getJson("/nova-api/member-profiles?viaResource=agencies&viaResourceId={$agency->id}&viaRelationship=members")
            ->assertOk()
            ->json('resources'))
            ->map(fn (array $row) => collect($row['fields'])->mapWithKeys(fn (array $field) => [$field['name'] => $field['value']])->all())
            ->all();
    }

    // 1. People list and license status

    public function test_people_list_shows_the_agency_columns_with_the_principal_first(): void
    {
        [$agency, $principal, , $employee] = $this->agencyWithEmployee();

        $this->actingAs($this->staff(Role::MembershipCoordinator));
        $rows = $this->rosterRows($agency);

        $this->assertCount(3, $rows);
        $this->assertSame(['ID', 'Name', 'Email', 'Person Type', 'Agency Role', 'NPN', 'Resident State', 'Invite Status', 'Status'], array_keys($rows[0]));
        $this->assertSame($principal->email, $rows[0]['Email']);
        $this->assertSame('principal', $rows[0]['Agency Role']);
        $this->assertSame($principal->memberProfile->fresh()->npn, $rows[0]['NPN']);

        $dakota = collect($rows)->firstWhere('Email', $employee->email);
        $this->assertSame('Dakota Lawson', $dakota['Name']);
        $this->assertSame('Agency employee', $dakota['Person Type']);
        $this->assertNull($dakota['NPN']);
        $this->assertNull($dakota['Resident State']);
    }

    public function test_agency_people_do_not_see_the_old_license_fields_but_individual_members_do(): void
    {
        [$agency, $principal] = $this->agencyWithEmployee();
        $individual = $this->member();

        $this->actingAs($this->staff(Role::MembershipCoordinator));

        $agencyFields = collect($this->getJson("/nova-api/member-profiles/{$principal->memberProfile->id}")->assertOk()->json('resource.fields'))->pluck('attribute');
        $this->assertNotContains('license_number', $agencyFields);
        $this->assertNotContains('license_type', $agencyFields);
        $this->assertNotContains('license_expires_at', $agencyFields);
        $this->assertContains('npn', $agencyFields);

        $individualFields = collect($this->getJson("/nova-api/member-profiles/{$individual->memberProfile->id}")->assertOk()->json('resource.fields'))->pluck('attribute');
        $this->assertContains('license_number', $individualFields);
        $this->assertContains('license_expires_at', $individualFields);

        $updateFields = collect($this->getJson("/nova-api/member-profiles/{$principal->memberProfile->id}/update-fields")->assertOk()->json('fields'))->pluck('attribute');
        $this->assertNotContains('license_number', $updateFields);
    }

    public function test_employees_have_no_license_status(): void
    {
        [, , , $employee] = $this->agencyWithEmployee();
        $profile = $employee->memberProfile;

        $this->assertNull($profile->fresh()->license_status);

        // Even with a date typed in by mistake.
        $profile->update(['license_expires_at' => today()->addDays(5)]);
        $this->assertNull($profile->fresh()->license_status);

        // A producer's status still follows the date; becoming an employee clears it.
        $producer = $this->member('expiring')->memberProfile;
        $this->assertSame(LicenseStatus::Expiring, $producer->fresh()->license_status);
        $producer->update(['person_type' => 'agency_employee']);
        $this->assertNull($producer->fresh()->license_status);

        $this->actingAs($this->staff(Role::MembershipCoordinator));
        $status = collect($this->getJson("/nova-api/member-profiles/{$profile->id}")->assertOk()->json('resource.fields'))->firstWhere('attribute', 'license_status');
        $this->assertSame('—', $status['label']);
    }

    public function test_employees_get_no_license_expiry_reminders(): void
    {
        Notification::fake();
        [, , , $employee] = $this->agencyWithEmployee();
        $employee->memberProfile->update(['license_expires_at' => today()->addDays(7)]);
        $producer = $this->member('expiring');
        $producer->memberProfile->update(['license_expires_at' => today()->addDays(7)]);

        $this->artisan('app:check-license-expirations')->assertSuccessful();

        $this->assertSame(0, ReminderLog::query()->where('user_id', $employee->id)->count());
        $this->assertSame(1, ReminderLog::query()->where('user_id', $producer->id)->count());
        $this->assertNull($employee->memberProfile->fresh()->license_status);
    }

    // 2. Principal eligibility

    public function test_principal_options_are_licensed_producers_of_this_agency_only(): void
    {
        [$agency, $principal, $producer, $employee] = $this->agencyWithEmployee();
        $other = $this->agency(people: 2);
        $free = $this->member();

        $ids = collect($this->actingAs($this->staff(Role::MembershipCoordinator))
            ->getJson("/nova-api/agencies/associatable/principal?resourceId={$agency->id}&search=&withTrashed=false")
            ->assertOk()
            ->json('resources'))
            ->pluck('value');

        $this->assertEqualsCanonicalizing([$principal->id, $producer->id], $ids->all());
        $this->assertNotContains($employee->id, $ids);
        $this->assertNotContains($free->id, $ids);
        $this->assertNotContains($other->principal_user_id, $ids);
    }

    public function test_a_forged_request_cannot_make_an_employee_the_principal(): void
    {
        [$agency, $principal, , $employee] = $this->agencyWithEmployee();

        $this->actingAs($this->staff(Role::ExecutiveDirector));

        $this->updateAgency($agency, ['principal' => $employee->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['principal' => 'agency employees cannot lead an agency']);

        $this->updateAgency($agency, ['principal' => $this->agency()->principal_user_id])
            ->assertJsonValidationErrors(['principal' => 'belongs to another agency']);

        $this->updateAgency($agency, ['principal' => $this->member()->id])
            ->assertJsonValidationErrors(['principal']);

        $this->assertSame($principal->id, $agency->fresh()->principal_user_id);
        $this->assertFalse($employee->fresh()->hasRole(Role::AgencyPrincipal->value));
    }

    public function test_the_model_refuses_an_ineligible_principal_outside_nova(): void
    {
        [$agency, $principal, , $employee] = $this->agencyWithEmployee();

        try {
            $agency->update(['principal_user_id' => $employee->id]);
            $this->fail('An employee became the principal.');
        } catch (IneligiblePrincipalException $e) {
            $this->assertStringNotContainsString('Dakota', $e->getMessage());
        }

        $this->assertSame($principal->id, $agency->fresh()->principal_user_id);

        $withoutNpn = $this->member();
        $withoutNpn->memberProfile->update(['npn' => null]);
        $this->expectException(IneligiblePrincipalException::class);
        Agency::factory()->create(['principal_user_id' => $withoutNpn->id]);
    }

    // 3. Tier and rate type

    public function test_tier_and_rate_type_are_read_only_in_the_form(): void
    {
        $agency = $this->agency(people: 2, attributes: ['tier' => AgencyTier::Agency, 'rate_type' => RateType::Founding]);

        $this->actingAs($this->staff(Role::ExecutiveDirector));

        $fields = collect($this->getJson("/nova-api/agencies/{$agency->id}/update-fields")->assertOk()->json('fields'))->keyBy('attribute');
        $this->assertTrue($fields['tier']['readonly']);
        $this->assertTrue($fields['rate_type']['readonly']);

        $this->updateAgency($agency, ['tier' => AgencyTier::AgencyEnterprise->value, 'rate_type' => RateType::Standard->value])->assertOk();

        $this->assertSame(AgencyTier::Agency, $agency->fresh()->tier);
        $this->assertSame(RateType::Founding, $agency->fresh()->rate_type);
    }

    public function test_a_new_agency_gets_its_tier_and_rate_type_from_the_rules(): void
    {
        $this->travelTo(now()->setDate(2027, 3, 1));

        $this->actingAs($this->staff(Role::MembershipCoordinator))
            ->postJson('/nova-api/agencies', [
                'legal_name' => 'Gulf Coast Benefits LLC',
                'agency_type' => 'health_and_life',
                'tier' => AgencyTier::AgencyEnterprise->value,
                'rate_type' => RateType::Founding->value,
                'status' => 'pending',
                'principal' => $this->member()->id,
                'legal_address' => '200 Main St, Houston, TX 77002',
                'mailing_same_as_legal' => true,
                'phone' => '(713) 555-0100',
                'billing_email' => 'billing@gulfcoast.test',
            ])
            ->assertCreated();

        $agency = Agency::query()->sole();
        $this->assertSame(AgencyTier::Agency, $agency->tier);
        $this->assertSame(RateType::Standard, $agency->rate_type);
    }

    public function test_the_executive_director_changes_the_tier_with_a_reason_and_it_is_audited(): void
    {
        $agency = $this->agency(people: 2, attributes: ['tier' => AgencyTier::Agency]);
        $director = $this->staff(Role::ExecutiveDirector);

        $this->actingAs($director);

        $this->postJson('/nova-api/agencies/action?action=change-agency-tier', ['resources' => (string) $agency->id, 'value' => 'agency_10to49'])
            ->assertJsonValidationErrors(['reason']);

        $this->postJson('/nova-api/agencies/action?action=change-agency-tier', [
            'resources' => (string) $agency->id,
            'value' => 'agency_10to49',
            'reason' => 'Board-approved exception for a merger.',
        ])->assertJsonValidationErrors(['reason']);

        $this->postJson('/nova-api/agencies/action?action=change-agency-tier', [
            'resources' => (string) $agency->id,
            'value' => 'agency_10to49',
            'reason' => 'approved_pricing_exception',
        ])->assertOk();

        $this->assertSame(AgencyTier::AgencyPlus, $agency->fresh()->tier);

        $log = AuditLog::query()->where('action', 'agency_tier_changed')->sole();
        $this->assertSame($director->id, $log->user_id);
        $this->assertSame(['from' => 'agency_2to9', 'to' => 'agency_10to49', 'reason' => 'approved_pricing_exception'], $log->details);
        $this->assertSame(0, AuditLog::query()->where('action', 'agency_updated')->count());

        $changes = collect($this->getJson("/nova-api/audit-logs/{$log->id}")->assertOk()->json('resource.fields'))->firstWhere('name', 'Changes');
        $this->assertSame('Tier: Agency → Agency Plus. Reason: Approved pricing exception', $changes['value']);

        $this->postJson('/nova-api/agencies/action?action=change-agency-rate-type', [
            'resources' => (string) $agency->id,
            'value' => 'standard',
            'reason' => 'renewal_adjustment',
        ])->assertOk();

        $this->assertSame(RateType::Standard, $agency->fresh()->rate_type);
        $this->assertSame(['from' => 'founding', 'to' => 'standard', 'reason' => 'renewal_adjustment'], AuditLog::query()->where('action', 'agency_rate_type_changed')->sole()->details);
    }

    public function test_only_the_executive_director_can_change_tier_or_rate_type(): void
    {
        $agency = $this->agency(people: 2, attributes: ['tier' => AgencyTier::Agency]);

        $this->actingAs($this->staff(Role::MembershipCoordinator));

        $actions = collect($this->getJson("/nova-api/agencies/actions?resources={$agency->id}")->assertOk()->json('actions'))->pluck('uriKey');
        $this->assertNotContains('change-agency-tier', $actions);
        $this->assertNotContains('change-agency-rate-type', $actions);

        // Nova answers 404 for an action the user cannot see.
        $status = $this->postJson('/nova-api/agencies/action?action=change-agency-tier', [
            'resources' => (string) $agency->id,
            'value' => 'agency_50plus',
            'reason' => 'other',
        ])->status();
        $this->assertContains($status, [403, 404]);

        $this->assertSame(AgencyTier::Agency, $agency->fresh()->tier);

        $this->actingAs($this->staff(Role::ExecutiveDirector));
        $actions = collect($this->getJson("/nova-api/agencies/actions?resources={$agency->id}")->assertOk()->json('actions'))->pluck('uriKey');
        $this->assertContains('change-agency-tier', $actions);
        $this->assertContains('change-agency-rate-type', $actions);
    }

    // 4–5. Audit log

    public function test_principal_change_records_the_old_and_new_principal_ids(): void
    {
        [$agency, $principal, $producer] = $this->agencyWithEmployee();

        $this->actingAs($this->staff(Role::MembershipCoordinator));
        $this->updateAgency($agency, ['principal' => $producer->id])->assertOk();

        $log = AuditLog::query()->where('action', 'agency_principal_changed')->latest('id')->first();
        $this->assertSame(['from_user_id' => $principal->id, 'to_user_id' => $producer->id], $log->details);

        $roles = AuditLog::query()->where('action', 'role_changed')->where('created_at', '>=', $log->created_at)->get()->keyBy('resource_id');
        $this->assertSame(['added' => [], 'removed' => ['Agency Principal']], $roles[$principal->id]->details);
        $this->assertSame(['added' => ['Agency Principal'], 'removed' => []], $roles[$producer->id]->details);
    }

    public function test_role_sync_records_the_net_change_once(): void
    {
        $user = $this->staff(Role::MembershipCoordinator);

        // A later request: entries are merged per request only.
        $this->app->instance('request', Request::create('/nova'));

        $user->syncRoles([Role::ComplianceSpecialist->value]);

        $log = AuditLog::query()->where('action', 'role_changed')->where('resource_id', $user->id)->latest('id')->first();
        $this->assertSame(2, AuditLog::query()->where('action', 'role_changed')->where('resource_id', $user->id)->count());
        $this->assertSame(['added' => ['Compliance Specialist'], 'removed' => ['Membership Coordinator']], $log->details);
    }

    public function test_agency_edits_record_field_names_but_no_values(): void
    {
        $agency = $this->agency(people: 2);

        $this->actingAs($this->staff(Role::MembershipCoordinator));
        $this->updateAgency($agency, ['phone' => '(512) 555-0199', 'legal_address' => '9 Elm St, Austin, TX 78702'])->assertOk();

        $log = AuditLog::query()->where('action', 'agency_updated')->latest('id')->first();
        $this->assertContains('phone', $log->details['fields']);
        $this->assertContains('legal_address', $log->details['fields']);
        $this->assertNotContains('fein_index', $log->details['fields']);

        $raw = (string) $log->getRawOriginal('details');
        foreach (['555-0199', 'Elm St', $agency->fein] as $value) {
            $this->assertStringNotContainsString($value, $raw);
        }
    }

    public function test_audit_log_shows_names_links_and_what_changed(): void
    {
        [$agency, $principal, $producer] = $this->agencyWithEmployee();
        $director = $this->staff(Role::ExecutiveDirector);

        $this->actingAs($director);
        $this->updateAgency($agency, ['principal' => $producer->id])->assertOk();

        $log = AuditLog::query()->where('action', 'agency_principal_changed')->latest('id')->first();
        $fields = collect($this->getJson("/nova-api/audit-logs/{$log->id}")->assertOk()->json('resource.fields'))->keyBy('name');

        // The link is HTML: names such as "D'Amore" arrive escaped.
        $this->assertStringContainsString(e("Agency: {$agency->legal_name}"), $fields['Resource']['value']);
        $this->assertStringContainsString("/resources/agencies/{$agency->id}", $fields['Resource']['value']);
        $this->assertSame("Principal: {$principal->name} (#{$principal->id}) → {$producer->name} (#{$producer->id})", $fields['Changes']['value']);

        // User entries link to the member; a deleted record keeps its ID.
        $roleLog = AuditLog::query()->where('action', 'role_changed')->where('resource_id', $producer->id)->latest('id')->first();
        $roleFields = collect($this->getJson("/nova-api/audit-logs/{$roleLog->id}")->assertOk()->json('resource.fields'))->keyBy('name');
        $this->assertStringContainsString("/resources/members/{$producer->id}", $roleFields['Resource']['value']);
        $this->assertSame('Role added: Agency Principal', $roleFields['Changes']['value']);

        $gone = AuditLog::record('agency_deleted', $deleted = Agency::factory()->create());
        $deleted->deleteQuietly();
        $goneFields = collect($this->getJson("/nova-api/audit-logs/{$gone->id}")->assertOk()->json('resource.fields'))->keyBy('name');
        $this->assertSame("Agency #{$deleted->id} (deleted)", $goneFields['Resource']['value']);
    }

    public function test_audit_log_filters_by_resource_type_and_action(): void
    {
        $agency = $this->agency(people: 2);

        $this->actingAs($this->staff(Role::ExecutiveDirector));

        $filters = collect($this->getJson('/nova-api/audit-logs/filters')->assertOk()->json());
        $resourceFilter = $filters->firstWhere('name', 'Resource')['class'];
        $actionFilter = $filters->firstWhere('name', 'Action')['class'];

        $encoded = base64_encode(json_encode([[$resourceFilter => Agency::class]]));
        $types = collect($this->getJson('/nova-api/audit-logs?perPage=100&filters='.$encoded)->assertOk()->json('resources'))
            ->map(fn (array $row) => AuditLog::query()->find($row['id']['value'])->resource_type)
            ->unique()->values();
        $this->assertSame([Agency::class], $types->all());

        $encoded = base64_encode(json_encode([[$actionFilter => 'agency_created']]));
        $ids = collect($this->getJson('/nova-api/audit-logs?filters='.$encoded)->assertOk()->json('resources'))->pluck('id.value');
        $this->assertSame(AuditLog::query()->where('action', 'agency_created')->pluck('id')->all(), $ids->all());
        $this->assertSame($agency->id, AuditLog::query()->find($ids->first())->resource_id);
    }
}
