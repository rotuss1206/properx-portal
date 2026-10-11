<?php

namespace Tests\Feature\Nova;

use App\Enums\Role;
use App\Enums\VerificationStatus;
use App\Models\AuditLog;
use App\Models\LibraryDocument;
use App\Models\LicenseDocument;
use App\Models\ReminderLog;
use App\Models\TaxDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * Staff role boundaries from Spec §3.7.
 */
class StaffPermissionsTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    public function test_coordinator_verifies_and_rejects_license_documents(): void
    {
        $document = LicenseDocument::factory()->pending()->for($this->member())->create();

        $this->actingAs($this->staff(Role::MembershipCoordinator))
            ->postJson('/nova-api/license-documents/action?action=reject-license-document', [
                'resources' => (string) $document->id,
                'admin_notes' => 'Expired copy, please upload the current license.',
            ])
            ->assertOk();

        $document->refresh();
        $this->assertSame(VerificationStatus::Rejected, $document->verification_status);
        $this->assertSame('Expired copy, please upload the current license.', $document->admin_notes);

        $this->postJson('/nova-api/license-documents/action?action=verify-license-document', [
            'resources' => (string) $document->id,
        ])->assertOk();

        $this->assertSame(VerificationStatus::Approved, $document->fresh()->verification_status);
    }

    public function test_reject_requires_admin_notes(): void
    {
        $document = LicenseDocument::factory()->pending()->for($this->member())->create();

        $this->actingAs($this->staff(Role::MembershipCoordinator))
            ->postJson('/nova-api/license-documents/action?action=reject-license-document', [
                'resources' => (string) $document->id,
            ])
            ->assertJsonValidationErrors('admin_notes');
    }

    public function test_member_cannot_verify_own_document(): void
    {
        $member = $this->member();
        $document = LicenseDocument::factory()->pending()->for($member)->create();

        $this->actingAs($member)
            ->postJson('/nova-api/license-documents/action?action=verify-license-document', [
                'resources' => (string) $document->id,
            ]);

        $this->assertSame(VerificationStatus::Pending, $document->fresh()->verification_status);
    }

    public function test_compliance_specialist_has_read_only_access_to_members(): void
    {
        $compliance = $this->staff(Role::ComplianceSpecialist);
        $profile = $this->member()->memberProfile;

        $this->assertTrue($compliance->can('view', $profile));
        $this->assertFalse($compliance->can('update', $profile));
        $this->assertFalse($compliance->can('create', $profile::class));

        $this->actingAs($compliance)
            ->putJson("/nova-api/member-profiles/{$profile->id}", ['first_name' => 'X', 'last_name' => 'Y'])
            ->assertForbidden();
    }

    public function test_compliance_specialist_manages_library_and_coordinator_does_not(): void
    {
        $document = LibraryDocument::factory()->create();

        $compliance = $this->staff(Role::ComplianceSpecialist);
        $coordinator = $this->staff(Role::MembershipCoordinator);

        $this->assertTrue($compliance->can('create', LibraryDocument::class));
        $this->assertTrue($compliance->can('update', $document));
        $this->assertFalse($coordinator->can('viewAny', LibraryDocument::class));
        $this->assertFalse($coordinator->can('create', LibraryDocument::class));
    }

    public function test_only_executive_director_manages_staff(): void
    {
        $director = $this->staff(Role::ExecutiveDirector);
        $coordinator = $this->staff(Role::MembershipCoordinator);
        $compliance = $this->staff(Role::ComplianceSpecialist);

        $this->assertTrue($director->can('createStaff', User::class));
        $this->assertTrue($director->can('update', $coordinator));
        $this->assertTrue($director->can('delete', $coordinator));

        foreach ([$coordinator, $compliance] as $user) {
            $this->assertFalse($user->can('createStaff', User::class));
            $this->assertFalse($user->can('update', $director));
            $this->assertFalse($user->can('view', $director));
        }

        $this->actingAs($coordinator)->getJson("/nova-api/staff/{$compliance->id}")->assertForbidden();
    }

    public function test_executive_director_cannot_be_deleted_from_the_ui(): void
    {
        $director = $this->staff(Role::ExecutiveDirector);
        $secondDirector = $this->staff(Role::ExecutiveDirector);

        $this->assertFalse($director->can('delete', $director));
        $this->assertFalse($director->can('delete', $secondDirector));
    }

    public function test_coordinator_cannot_see_tax_documents_or_audit_log(): void
    {
        $coordinator = $this->staff(Role::MembershipCoordinator);

        $this->assertFalse($coordinator->can('viewAny', TaxDocument::class));
        $this->assertFalse($coordinator->can('viewAny', AuditLog::class));
        $this->assertTrue($coordinator->can('viewAny', ReminderLog::class));
    }

    public function test_logs_are_read_only_even_for_executive_director(): void
    {
        $director = $this->staff(Role::ExecutiveDirector);

        $this->assertTrue($director->can('viewAny', AuditLog::class));
        $this->assertFalse($director->can('create', AuditLog::class));
        $this->assertFalse($director->can('create', ReminderLog::class));
    }
}
