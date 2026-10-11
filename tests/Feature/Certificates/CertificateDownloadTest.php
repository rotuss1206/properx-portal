<?php

namespace Tests\Feature\Certificates;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

class CertificateDownloadTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        Storage::fake(config('portal.certificates.disk'));
    }

    public function test_member_downloads_own_certificate(): void
    {
        $member = $this->member();

        $this->actingAs($member)
            ->get(route('certificates.download', $member->memberProfile))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertDownload("ProPerx-membership-certificate-{$member->memberProfile->certificate_code}.pdf");

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'certificate_downloaded',
            'user_id' => $member->id,
            'resource_id' => $member->memberProfile->id,
        ]);
    }

    public function test_member_cannot_download_someone_elses_certificate(): void
    {
        $other = $this->member();

        $this->actingAs($this->member())
            ->get(route('certificates.download', $other->memberProfile))
            ->assertForbidden();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'certificate_downloaded']);
    }

    /**
     * @return array<string, array{Role, int}>
     */
    public static function staffAccess(): array
    {
        return [
            'Executive Director' => [Role::ExecutiveDirector, 200],
            'Membership Coordinator' => [Role::MembershipCoordinator, 200],
            'Compliance Specialist' => [Role::ComplianceSpecialist, 200],
        ];
    }

    #[DataProvider('staffAccess')]
    public function test_staff_can_generate_any_members_certificate(Role $role, int $status): void
    {
        $profile = $this->member()->memberProfile;

        $this->actingAs($this->staff($role))
            ->get(route('certificates.download', $profile))
            ->assertStatus($status);
    }

    public function test_guests_and_api_service_cannot_download(): void
    {
        $profile = $this->member()->memberProfile;

        $this->get(route('certificates.download', $profile))->assertRedirect();

        $this->actingAs($this->apiService())
            ->get(route('certificates.download', $profile))
            ->assertForbidden();
    }

    public function test_expired_license_gets_a_clear_error(): void
    {
        $member = $this->member('expired');

        $this->actingAs($member)
            ->get(route('certificates.download', $member->memberProfile))
            ->assertUnprocessable()
            ->assertSee('the license expired on');
    }

    public function test_member_nova_action_returns_download_for_own_profile_only(): void
    {
        $member = $this->member();
        $other = $this->member();

        $this->actingAs($member)
            ->postJson('/nova-api/member-profiles/action?action=download-my-certificate', [
                'resources' => (string) $member->memberProfile->id,
            ])
            ->assertOk()
            ->assertJsonPath('download.url', route('certificates.download', $member->memberProfile));

        $this->postJson('/nova-api/member-profiles/action?action=download-my-certificate', [
            'resources' => (string) $other->memberProfile->id,
        ])->assertJsonMissingPath('download');
    }

    public function test_staff_nova_action_reports_ineligible_profile(): void
    {
        $profile = $this->member('expired')->memberProfile;

        $this->actingAs($this->staff(Role::MembershipCoordinator))
            ->postJson('/nova-api/member-profiles/action?action=generate-certificate', [
                'resources' => (string) $profile->id,
            ])
            ->assertOk()
            ->assertJsonPath('danger', fn (string $message) => str_contains($message, 'expired'));
    }
}
