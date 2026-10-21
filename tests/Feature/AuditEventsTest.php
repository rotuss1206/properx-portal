<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\LicenseDocument;
use Database\Seeders\Support\DemoFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * Remaining audit events from Spec 8: password change, 2FA enrolment, profile edit,
 * role change, document upload and deletion.
 */
class AuditEventsTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        Storage::fake(config('portal.documents_disk'));
    }

    private function auditCount(string $action, ?int $userId = null): int
    {
        return AuditLog::where('action', $action)
            ->when($userId, fn ($query) => $query->where('user_id', $userId))
            ->count();
    }

    public function test_password_change_is_audited(): void
    {
        $member = $this->member();

        $this->actingAs($member);
        $member->update(['password' => 'a-new-long-password']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'password_changed',
            'user_id' => $member->id,
            'resource_id' => $member->id,
        ]);
    }

    public function test_two_factor_enrolment_and_removal_are_audited(): void
    {
        $member = $this->member(withTwoFactor: false);
        $this->actingAs($member);

        $member->forceFill(['two_factor_secret' => encrypt('SECRET')])->save();
        $this->assertSame(0, $this->auditCount('mfa_enrolled'), 'Not enrolled until confirmed.');

        $member->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->assertSame(1, $this->auditCount('mfa_enrolled', $member->id));

        $member->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        $this->assertSame(1, $this->auditCount('mfa_disabled', $member->id));
    }

    public function test_profile_edit_is_audited_but_status_refresh_is_not(): void
    {
        $member = $this->member();
        $profile = $member->memberProfile;

        $this->actingAs($member)
            ->putJson("/nova-api/member-profiles/{$profile->id}", [
                'first_name' => 'Edited',
                'last_name' => $profile->last_name,
                'license_expires_at' => $profile->license_expires_at->toDateString(),
            ])
            ->assertOk();

        $this->assertSame(1, $this->auditCount('profile_updated', $member->id));

        // System-only change (nightly job) does not count as a profile edit.
        $profile->refresh()->forceFill(['license_status' => 'expired'])->save();
        $this->assertSame(1, $this->auditCount('profile_updated'));
    }

    public function test_role_change_by_executive_director_is_audited_once(): void
    {
        $director = $this->staff(Role::ExecutiveDirector);
        $coordinator = $this->staff(Role::MembershipCoordinator);
        AuditLog::query()->delete(); // ignore role assignments made while creating users

        $this->actingAs($director)
            ->putJson("/nova-api/staff/{$coordinator->id}", [
                'name' => $coordinator->name,
                'email' => $coordinator->email,
                'role' => Role::ComplianceSpecialist->value,
            ])
            ->assertOk();

        $this->assertTrue($coordinator->fresh()->hasRole(Role::ComplianceSpecialist->value));
        $this->assertSame(1, AuditLog::where('action', 'role_changed')->where('resource_id', $coordinator->id)->count());
        $this->assertSame($director->id, AuditLog::where('action', 'role_changed')->value('user_id'));
    }

    public function test_document_upload_and_deletion_are_audited(): void
    {
        $member = $this->member();

        $this->actingAs($member)
            ->post('/nova-api/license-documents', [
                'stored_filename' => UploadedFile::fake()->createWithContent('license.pdf', DemoFile::pdf('License')),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $document = LicenseDocument::sole();
        $this->assertSame(1, $this->auditCount('document_uploaded', $member->id));

        // Members may withdraw their own pending document.
        $this->deleteJson('/nova-api/license-documents?resources[]='.$document->id)->assertOk();

        $this->assertModelMissing($document);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document_deleted',
            'user_id' => $member->id,
            'resource_type' => $document->getMorphClass(),
            'resource_id' => $document->id,
        ]);
    }
}
