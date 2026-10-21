<?php

namespace Tests\Feature\Stripe;

use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\LibraryDocument;
use App\Models\LicenseDocument;
use App\Models\TaxDocument;
use App\Models\User;
use App\Services\Certificates\CertificateGeneratorService;
use App\Support\MembershipBanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * Access for past-due and canceled memberships (Spec 7). Login is never blocked.
 */
class MembershipRestrictionTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        Storage::fake(config('portal.certificates.disk'));
        config(['portal.payments.payment_url' => 'https://billing.example.com/portal', 'portal.payments.grace_days' => 14]);
    }

    private function memberWithPayment(PaymentStatus $status, ?int $daysAgo = 1): User
    {
        $member = $this->member();
        $member->memberProfile->forceFill([
            'payment_status' => $status,
            'payment_status_updated_at' => $daysAgo === null ? null : now()->subDays($daysAgo),
        ])->save();

        return $member->fresh(['memberProfile']);
    }

    /**
     * @return array<string, array{PaymentStatus, ?int, bool}>
     */
    public static function scenarios(): array
    {
        return [
            'active' => [PaymentStatus::Active, 100, false],
            'unknown' => [PaymentStatus::Unknown, null, false],
            'past due within grace' => [PaymentStatus::PastDue, 13, false],
            'past due after grace' => [PaymentStatus::PastDue, 15, true],
            'canceled' => [PaymentStatus::Canceled, 1, true],
        ];
    }

    #[DataProvider('scenarios')]
    public function test_access_by_payment_status(PaymentStatus $status, ?int $daysAgo, bool $restricted): void
    {
        $member = $this->memberWithPayment($status, $daysAgo);
        LicenseDocument::factory()->for($member)->create();
        TaxDocument::factory()->for($member)->create();
        LibraryDocument::factory()->published()->create();

        $this->actingAs($member);
        $closed = $restricted ? 403 : 200;

        // Always available: login, dashboard, profile (with notification settings), tax documents.
        $this->get('/nova/dashboards/my-membership')->assertOk();
        $this->getJson("/nova-api/member-profiles/{$member->memberProfile->id}")->assertOk();
        $this->getJson('/nova-api/tax-documents')->assertOk();

        // Closed while restricted.
        $this->getJson('/nova-api/library-documents')->assertStatus($closed);
        $this->getJson('/nova-api/license-documents')->assertStatus($closed);
        $this->get(CertificateGeneratorService::downloadUrl($member->memberProfile))->assertStatus($restricted ? 422 : 200);
    }

    public function test_restricted_member_can_still_log_in(): void
    {
        $member = $this->memberWithPayment(PaymentStatus::Canceled);

        // Credentials are accepted and the member moves on to the (mandatory) 2FA challenge.
        $this->postJson('/nova/login', ['email' => $member->email, 'password' => 'password'])
            ->assertOk()
            ->assertJson(['two_factor' => true]);
    }

    public function test_certificate_refusal_explains_why(): void
    {
        $member = $this->memberWithPayment(PaymentStatus::Canceled);

        $this->actingAs($member)
            ->get(CertificateGeneratorService::downloadUrl($member->memberProfile))
            ->assertUnprocessable()
            ->assertSee('membership is inactive');
    }

    public function test_menu_hides_closed_sections_for_restricted_members(): void
    {
        $menu = $this->actingAs($this->memberWithPayment(PaymentStatus::Canceled))
            ->get('/nova/dashboards/my-membership')
            ->viewData('page')['props']['novaConfig']['mainMenu'];
        $json = json_encode($menu, JSON_UNESCAPED_SLASHES);

        $this->assertStringContainsString('/nova/resources/member-profiles', $json);
        $this->assertStringContainsString('/nova/resources/tax-documents', $json);
        $this->assertStringNotContainsString('/nova/resources/library-documents', $json);
        $this->assertStringNotContainsString('/nova/resources/license-documents', $json);
    }

    public function test_banner_for_past_due_and_inactive_members(): void
    {
        $this->assertNull(MembershipBanner::for($this->memberWithPayment(PaymentStatus::Active)));

        $grace = MembershipBanner::for($this->memberWithPayment(PaymentStatus::PastDue, 3));
        $this->assertSame('warning', $grace['level']);
        $this->assertStringContainsString(now()->subDays(3)->addDays(14)->toFormattedDateString(), $grace['message']);
        $this->assertSame('https://billing.example.com/portal', $grace['paymentUrl']);

        $inactive = MembershipBanner::for($this->memberWithPayment(PaymentStatus::Canceled));
        $this->assertSame('danger', $inactive['level']);
    }

    public function test_banner_reaches_the_nova_page(): void
    {
        $member = $this->memberWithPayment(PaymentStatus::PastDue, 3);

        $config = $this->actingAs($member)
            ->get('/nova/dashboards/my-membership')
            ->assertOk()
            ->viewData('page')['props']['novaConfig'];

        $this->assertSame('warning', $config['membershipBanner']['level']);
    }

    public function test_staff_cannot_issue_certificates_for_inactive_members(): void
    {
        $profile = $this->memberWithPayment(PaymentStatus::Canceled)->memberProfile;

        $this->actingAs($this->staff(Role::MembershipCoordinator))
            ->postJson('/nova-api/member-profiles/action?action=generate-certificate', ['resources' => (string) $profile->id])
            ->assertJsonPath('danger', fn (string $message) => str_contains($message, 'inactive'));
    }

    public function test_verification_page_shows_inactive_membership(): void
    {
        $profile = $this->memberWithPayment(PaymentStatus::Canceled)->memberProfile;

        $this->get(route('certificates.verify', $profile->certificate_code))
            ->assertOk()
            ->assertSee('Membership is not active')
            ->assertDontSee('Valid certificate');
    }
}
