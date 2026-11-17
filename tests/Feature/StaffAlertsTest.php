<?php

namespace Tests\Feature;

use App\Enums\AgencyStatus;
use App\Enums\LicenseVerification;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Enums\VerificationStatus;
use App\Models\AuditLog;
use App\Models\LicenseDocument;
use App\Models\User;
use App\Notifications\Staff\StaffDailySummary;
use App\Services\Memberships\MembershipRenewals;
use App\Services\StaffAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Nova\Notifications\Notification as NovaNotificationRecord;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * Staff's bell: only what needs doing, each role its own, once per record while unread; and the
 * morning summary.
 */
class StaffAlertsTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    private User $director;

    private User $coordinator;

    private User $compliance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->apiService();
        $this->director = $this->staff(Role::ExecutiveDirector);
        $this->coordinator = $this->staff(Role::MembershipCoordinator);
        $this->compliance = $this->staff(Role::ComplianceSpecialist);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bell(User $user): array
    {
        return NovaNotificationRecord::query()->where('notifiable_id', $user->id)->get()->map(fn ($row) => is_array($row->data) ? $row->data : json_decode((string) $row->data, true))->all();
    }

    public function test_each_kind_reaches_its_role_only(): void
    {
        foreach (StaffAlerts::KINDS as $kind => [$role]) {
            StaffAlerts::raise($kind, 'record:'.$kind, 'Something about record #1.', '/resources/agencies/1');
        }

        $coordinatorKinds = array_column($this->bell($this->coordinator), 'ppx_kind');
        $directorKinds = array_column($this->bell($this->director), 'ppx_kind');

        $this->assertEqualsCanonicalizing(['license_document_uploaded', 'member_intake', 'license_fixed', 'invitation_requested', 'rejoin_blocked'], $coordinatorKinds);
        $this->assertEqualsCanonicalizing(['payment_failed', 'membership_ended', 'integration_refused'], $directorKinds);
        $this->assertSame([], $this->bell($this->compliance));

        // A click opens the record.
        $this->assertSame('/nova/resources/agencies/1', $this->bell($this->director)[0]['actionUrl']['url']);
    }

    public function test_the_same_record_rings_once_while_unread(): void
    {
        $agency = $this->agency(2);

        StaffAlerts::raise('payment_failed', $agency, 'Payment failed (agency #'.$agency->id.').', '/resources/agencies/'.$agency->id);
        StaffAlerts::raise('payment_failed', $agency, 'Payment failed (agency #'.$agency->id.').', '/resources/agencies/'.$agency->id);
        $this->assertCount(1, $this->bell($this->director));

        // Another record rings separately.
        StaffAlerts::raise('payment_failed', $this->agency(2), 'Payment failed.', '/resources/agencies/2');
        $this->assertCount(2, $this->bell($this->director));

        // Once read ("Mark all as read"), the same record may ring again.
        NovaNotificationRecord::query()->update(['read_at' => now()]);
        StaffAlerts::raise('payment_failed', $agency, 'Payment failed (agency #'.$agency->id.').', '/resources/agencies/'.$agency->id);
        $this->assertCount(3, $this->bell($this->director));
    }

    public function test_a_member_s_license_upload_rings_the_coordinator_but_staff_uploads_do_not(): void
    {
        $member = $this->member();

        $this->actingAs($member);
        $document = LicenseDocument::factory()->for($member)->create(['verification_status' => VerificationStatus::Pending]);
        $this->assertSame(['license_document_uploaded'], array_column($this->bell($this->coordinator), 'ppx_kind'));
        $this->assertStringNotContainsString($member->name, json_encode($this->bell($this->coordinator)));
        $this->assertStringContainsString('#'.$document->id, $this->bell($this->coordinator)[0]['message']);

        $this->actingAs($this->coordinator);
        LicenseDocument::factory()->for($member)->create();
        $this->assertCount(1, $this->bell($this->coordinator));
    }

    public function test_a_license_fixed_after_needs_attention_rings_the_coordinator(): void
    {
        $member = $this->member();
        $profile = $member->memberProfile;
        $profile->forceFill(['license_verification' => LicenseVerification::NeedsAttention, 'license_verification_note' => 'Wrong state.'])->saveQuietly();

        $this->actingAs($member);
        $profile->fresh()->update(['license_state' => 'OH']);

        $this->assertSame(LicenseVerification::PendingReview, $profile->fresh()->license_verification);
        $this->assertSame(['license_fixed'], array_column($this->bell($this->coordinator), 'ppx_kind'));
        $this->assertSame('/nova/resources/license-verification/'.$profile->id, $this->bell($this->coordinator)[0]['actionUrl']['url']);
    }

    public function test_an_individual_s_failed_payment_and_an_ended_agency_ring_the_director(): void
    {
        $member = $this->member();
        $member->memberProfile->forceFill(['stripe_customer_id' => 'cus_member', 'payment_status' => PaymentStatus::Active])->saveQuietly();
        config(['services.stripe.webhook_secret' => 'whsec_test']);

        $payload = json_encode(['id' => 'evt_1', 'object' => 'event', 'type' => 'invoice.payment_failed', 'created' => now()->getTimestamp(), 'data' => ['object' => ['id' => 'in_1', 'object' => 'invoice', 'customer' => 'cus_member']]]);
        $t = time();
        $this->call('POST', route('webhooks.stripe'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$t},v1=".hash_hmac('sha256', "{$t}.{$payload}", 'whsec_test')], $payload)->assertOk();

        $agency = $this->agency(2, ['status' => AgencyStatus::PastDue]);
        app(MembershipRenewals::class)->expireAgency($agency);

        $this->assertEqualsCanonicalizing(['payment_failed', 'membership_ended'], array_column($this->bell($this->director), 'ppx_kind'));
        $this->assertSame([], $this->bell($this->coordinator));
    }

    public function test_a_refused_integration_is_audited_and_rings_the_director_once(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test', 'portal.intake.enabled' => true, 'portal.intake.secret' => 'intake-secret']);

        $this->call('POST', route('webhooks.stripe'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't=1,v1=bad'], '{}')->assertStatus(400);
        $this->call('POST', route('webhooks.stripe'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't=1,v1=bad'], '{}')->assertStatus(400);
        $this->postJson('/api/intake/cancellation', [], ['X-Timestamp' => (string) time(), 'X-Signature' => 'bad'])->assertUnauthorized();

        $this->assertSame(['integration_refused', 'integration_refused'], array_column($this->bell($this->director), 'ppx_kind'));
        $this->assertSame(3, AuditLog::query()->where('action', 'integration_refused')->count());
        $this->assertSame(['source' => 'stripe_webhook', 'reason' => 'signature'], AuditLog::query()->where('action', 'integration_refused')->first()->details);
        $this->assertSame([], $this->bell($this->coordinator));
    }

    public function test_the_morning_summary_is_off_by_default_and_then_tells_each_their_own(): void
    {
        Notification::fake();
        $member = $this->member();
        LicenseDocument::factory()->for($member)->create(['verification_status' => VerificationStatus::Pending]);

        $this->artisan('app:send-staff-summary')->expectsOutputToContain('off')->assertSuccessful();
        Notification::assertNothingSent();

        config(['portal.staff_summary.enabled' => true]);
        $this->artisan('app:send-staff-summary')->assertSuccessful();

        Notification::assertSentTo($this->coordinator, StaffDailySummary::class, fn (StaffDailySummary $summary) => collect($summary->lines)->contains(fn ($line) => str_contains($line['label'], 'license document') && $line['count'] === 1));
        // Nothing waits for the director, and the compliance specialist gets none.
        Notification::assertNotSentTo($this->director, StaffDailySummary::class);
        Notification::assertNotSentTo($this->compliance, StaffDailySummary::class);
    }

    public function test_the_summary_carries_no_personal_data(): void
    {
        $mail = (new StaffDailySummary([['label' => 'license document(s) waiting for review', 'count' => 3]]))->toMail($this->coordinator)->render();

        $this->assertStringContainsString('3 license document(s) waiting for review', (string) $mail);
        $this->assertStringNotContainsString($this->coordinator->email, (string) $mail);
    }
}
