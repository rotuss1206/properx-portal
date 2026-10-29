<?php

namespace Tests\Feature\Certificates;

use App\Enums\Role;
use App\Services\Certificates\CertificateGeneratorService;
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
            ->get(CertificateGeneratorService::downloadUrl($member->memberProfile))
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
            ->get(CertificateGeneratorService::downloadUrl($other->memberProfile))
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
            ->get(CertificateGeneratorService::downloadUrl($profile))
            ->assertStatus($status);
    }

    public function test_guests_and_api_service_cannot_download(): void
    {
        $profile = $this->member()->memberProfile;

        $this->get(CertificateGeneratorService::downloadUrl($profile))->assertRedirect();

        $this->actingAs($this->apiService())
            ->get(CertificateGeneratorService::downloadUrl($profile))
            ->assertForbidden();
    }

    public function test_expired_license_gets_a_clear_error(): void
    {
        $member = $this->member('expired');

        $this->actingAs($member)
            ->get(CertificateGeneratorService::downloadUrl($member->memberProfile))
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
            ->assertJsonPath('download.url', fn (string $url) => str_starts_with($url, route('certificates.download', $member->memberProfile)) && str_contains($url, 'signature='));

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

    public function test_the_profile_button_hands_over_a_signed_download_link(): void
    {
        $member = $this->member();

        $response = $this->actingAs($member)->get(route('certificates.open', $member->memberProfile))->assertRedirect();

        $this->assertStringContainsString('/certificates/'.$member->memberProfile->id.'/download?expires=', $response->headers->get('Location'));
        $this->assertStringContainsString('signature=', $response->headers->get('Location'));
    }

    public function test_the_profile_button_checks_access_and_eligibility(): void
    {
        $other = $this->member();

        $this->actingAs($this->member())->get(route('certificates.open', $other->memberProfile))->assertForbidden();

        $expired = $this->member('expired');
        $this->actingAs($expired)->get(route('certificates.open', $expired->memberProfile))
            ->assertUnprocessable()
            ->assertSee('the license expired on');

        auth()->logout();
        $this->get(route('certificates.open', $other->memberProfile))->assertRedirect();
    }

    public function test_the_member_profile_shows_the_certificate_block_with_the_button(): void
    {
        $member = $this->member();

        $fields = collect($this->actingAs($member)
            ->getJson('/nova-api/member-profiles/'.$member->memberProfile->id)
            ->assertOk()
            ->json('resource.fields'));

        $certificate = $fields->firstWhere('panel', 'Membership Certificate');

        $this->assertNotNull($certificate);
        $this->assertStringContainsString(route('certificates.open', $member->memberProfile), $certificate['value']);
        $this->assertStringContainsString('Download certificate', $certificate['value']);
    }
}
