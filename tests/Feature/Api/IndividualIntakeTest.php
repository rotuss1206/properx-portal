<?php

namespace Tests\Feature\Api;

use App\Enums\LicenseVerification;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\IntakeSubmission;
use App\Models\MemberProfile;
use App\Models\User;
use App\Notifications\MemberInvited;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * POST /api/intake/individual: paid applications from the marketing site become members.
 */
class IndividualIntakeTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    private const SECRET = 'test-intake-secret';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->apiService();
        config(['portal.intake.enabled' => true, 'portal.intake.secret' => self::SECRET, 'portal.intake.send_invite' => false]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'entry_id' => '101',
            'email' => 'New.Applicant@example.test',
            'first_name' => 'Avery',
            'last_name' => 'Stone',
            'phone' => '(216) 555-0100',
            'address' => '1 Main St, Cleveland, Ohio 44128',
            'firm_name' => 'Stone Insurance',
            'npn' => '12345678',
            'license_expires_at' => today()->addYear()->toDateString(),
            'license_state' => 'Ohio',
            'license_types' => ['Life', 'Health'],
            'stripe_customer_id' => 'cus_Test123',
            'stripe_subscription_id' => 'sub_Test123',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>|string  $payload
     */
    private function send(array|string $payload, ?int $timestamp = null, string $secret = self::SECRET): TestResponse
    {
        $body = is_string($payload) ? $payload : json_encode($payload);
        $timestamp ??= now()->getTimestamp();

        return $this->call('POST', '/api/intake/individual', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, $secret),
        ], $body);
    }

    public function test_a_paid_application_creates_a_member_with_encrypted_fields_and_no_password_anyone_knows(): void
    {
        Notification::fake();

        $response = $this->send($this->payload())->assertOk()->assertJsonPath('outcome', 'created')->assertJsonPath('conflicts', []);

        $user = User::where('email', 'new.applicant@example.test')->firstOrFail();
        $profile = $user->memberProfile;

        $this->assertTrue($user->hasRole(Role::Member->value));
        $this->assertFalse(Hash::check('password', $user->password));
        $this->assertSame($profile->id, $response->json('member_profile_id'));
        $this->assertSame('Avery Stone', $user->name);
        $this->assertSame('12345678', $profile->npn);
        $this->assertSame('OH', $profile->license_state);
        $this->assertSame('Life, Health', $profile->license_type);
        $this->assertSame('cus_Test123', $profile->stripe_customer_id);
        $this->assertSame('sub_Test123', $profile->stripe_subscription_id);
        $this->assertSame(LicenseVerification::PendingReview, $profile->license_verification);

        // Left to the Stripe webhook.
        $this->assertNull($profile->paid_through_at);
        $this->assertSame(PaymentStatus::Unknown, $profile->payment_status);

        // Encrypted at rest, found through the blind index.
        $this->assertStringNotContainsString('12345678', (string) $profile->getRawOriginal('npn'));
        $this->assertStringNotContainsString('555-0100', (string) $profile->getRawOriginal('phone'));
        $this->assertTrue(MemberProfile::query()->whereBlindIndex('npn', '12345678')->exists());

        // Audited without member data, as the service account.
        $audit = AuditLog::where('action', 'member_intake_created')->firstOrFail();
        $this->assertSame($this->apiService()->id, $audit->user_id);
        $this->assertSame(['source' => 'properx.org', 'entry_id' => '101'], $audit->details);

        Notification::assertNothingSent();
    }

    public function test_the_invite_goes_out_only_when_switched_on(): void
    {
        Notification::fake();
        config(['portal.intake.send_invite' => true]);

        $this->send($this->payload())->assertOk();

        Notification::assertSentTo(User::where('email', 'new.applicant@example.test')->first(), MemberInvited::class);

        // Linking an existing member invites nobody.
        Notification::fake();
        $member = $this->member();
        $this->send($this->payload(['entry_id' => '102', 'email' => $member->email, 'npn' => '999', 'stripe_customer_id' => 'cus_Other', 'stripe_subscription_id' => 'sub_Other']))
            ->assertOk()->assertJsonPath('outcome', 'linked');
        Notification::assertNothingSent();
    }

    public function test_the_invite_follows_the_flag_and_reaches_the_queue_with_a_set_password_link(): void
    {
        Notification::fake();
        config(['portal.intake.send_invite' => false]);

        $this->send($this->payload(['entry_id' => '201', 'email' => 'flag.off@example.test']))->assertOk()->assertJsonPath('outcome', 'created');
        Notification::assertNothingSent();

        config(['portal.intake.send_invite' => true]);

        $this->send($this->payload(['entry_id' => '202', 'email' => 'flag.on@example.test', 'npn' => '222', 'stripe_customer_id' => 'cus_On', 'stripe_subscription_id' => 'sub_On']))
            ->assertOk()->assertJsonPath('outcome', 'created');

        $user = User::where('email', 'flag.on@example.test')->first();
        Notification::assertSentTo($user, MemberInvited::class);
        Notification::assertSentTimes(MemberInvited::class, 1);

        // Queued, like every email of the portal; the link sets the password and carries no member data.
        $this->assertInstanceOf(ShouldQueue::class, new MemberInvited);

        $mail = (new MemberInvited)->toMail($user);
        $html = (string) $mail->render();

        $this->assertSame('Your ProPerX Alliance member portal account', $mail->subject);
        $this->assertStringContainsString('ProPerX Alliance', $html);
        $this->assertStringNotContainsString('ProPerx', $html);
        $this->assertStringContainsString('/nova/password/reset/', $mail->actionUrl);
        $this->assertTrue(Password::broker()->tokenExists($user, Str::between($mail->actionUrl, '/nova/password/reset/', '?') ?: Str::afterLast($mail->actionUrl, '/')));
        $this->assertStringNotContainsString('Avery', $html);
    }

    public function test_a_wrong_or_missing_signature_gets_401_without_details(): void
    {
        $this->send($this->payload(), secret: 'wrong')->assertUnauthorized()->assertExactJson(['error' => 'Unauthorized.']);

        $this->call('POST', '/api/intake/individual', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($this->payload()))
            ->assertUnauthorized();

        // The signature covers the exact body.
        $timestamp = now()->getTimestamp();
        $this->call('POST', '/api/intake/individual', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $timestamp.'.{"entry_id":"1"}', self::SECRET),
        ], json_encode($this->payload()))->assertUnauthorized();

        // No secret configured: nothing can be valid, not even a signature made with an empty key.
        config(['portal.intake.secret' => '']);
        $this->send($this->payload(), secret: '')->assertUnauthorized();

        $this->assertSame(0, IntakeSubmission::count());
        $this->assertNull(User::where('email', 'new.applicant@example.test')->first());
    }

    public function test_timestamps_more_than_five_minutes_off_are_refused(): void
    {
        $this->send($this->payload(), now()->subSeconds(301)->getTimestamp())->assertUnauthorized();
        $this->send($this->payload(), now()->addSeconds(301)->getTimestamp())->assertUnauthorized();
        $this->send($this->payload(), now()->subSeconds(290)->getTimestamp())->assertOk();
    }

    public function test_a_repeat_returns_the_same_result_without_duplicates(): void
    {
        $first = $this->send($this->payload())->assertOk()->json();
        $userCount = User::count();
        $auditCount = AuditLog::where('action', 'like', 'member_intake_%')->count();

        $this->assertSame($first, $this->send($this->payload())->assertOk()->json());

        // Same entry, even with other values: the stored answer, no changes.
        $this->assertSame($first, $this->send($this->payload(['email' => 'someone.else@example.test']))->assertOk()->json());

        $this->assertSame($userCount, User::count());
        $this->assertSame(1, IntakeSubmission::count());
        $this->assertSame($auditCount, AuditLog::where('action', 'like', 'member_intake_%')->count());
    }

    public function test_an_existing_member_is_linked_blanks_filled_and_conflicts_audited_by_field_name(): void
    {
        $member = $this->member();
        $profile = $member->memberProfile;
        $profile->forceFill(['firm_name' => null, 'license_verification' => LicenseVerification::Verified])->save();
        $originalNpn = $profile->npn;
        $originalFirstName = $profile->first_name;

        $response = $this->send($this->payload(['email' => strtoupper($member->email), 'first_name' => 'Different', 'npn' => '87654321', 'firm_name' => 'New Firm']))
            ->assertOk()
            ->assertJsonPath('outcome', 'linked')
            ->assertJsonPath('member_profile_id', $profile->id);

        $profile->refresh();
        $this->assertSame(1, User::where('email', $member->email)->count());
        $this->assertSame($originalNpn, $profile->npn);
        $this->assertSame($originalFirstName, $profile->first_name);
        $this->assertSame(LicenseVerification::Verified, $profile->license_verification);
        $this->assertSame('New Firm', $profile->firm_name);
        $this->assertSame('cus_Test123', $profile->stripe_customer_id);
        $this->assertContains('npn', $response->json('conflicts'));
        $this->assertContains('first_name', $response->json('conflicts'));

        $audit = AuditLog::where('action', 'member_intake_linked')->firstOrFail();
        $this->assertContains('npn', $audit->details['conflicts']);
        $this->assertContains('firm_name', $audit->details['filled']);
        $this->assertStringNotContainsString('87654321', json_encode($audit->details));
        $this->assertStringNotContainsString('Different', json_encode($audit->details));
    }

    public function test_stripe_ids_on_file_and_npns_of_others_are_not_overwritten(): void
    {
        $other = $this->member();
        $other->memberProfile->forceFill(['stripe_customer_id' => 'cus_Taken'])->save();

        $response = $this->send($this->payload(['npn' => $other->memberProfile->npn, 'stripe_customer_id' => 'cus_Taken']))
            ->assertOk()->assertJsonPath('outcome', 'created');

        $this->assertEqualsCanonicalizing(['npn', 'stripe_customer_id'], $response->json('conflicts'));

        $profile = User::where('email', 'new.applicant@example.test')->first()->memberProfile;
        $this->assertNull($profile->npn);
        $this->assertNull($profile->stripe_customer_id);
        $this->assertSame('cus_Taken', $other->memberProfile->fresh()->stripe_customer_id);
    }

    public function test_staff_and_agency_people_are_not_touched(): void
    {
        $staff = $this->staff(Role::MembershipCoordinator);

        $this->send($this->payload(['email' => $staff->email]))
            ->assertOk()
            ->assertExactJson(['outcome' => 'conflict', 'member_profile_id' => null, 'conflicts' => ['account_type']]);

        $this->assertNull($staff->fresh()->memberProfile);
        $this->assertFalse($staff->fresh()->hasRole(Role::Member->value));

        $agency = $this->agency(2);
        $person = $agency->principal()->first();
        $customer = $person->memberProfile->stripe_customer_id;

        $this->send($this->payload(['entry_id' => '102', 'email' => $person->email]))
            ->assertOk()->assertJsonPath('outcome', 'conflict')->assertJsonPath('conflicts', ['agency_member']);

        $this->assertSame($customer, $person->memberProfile->fresh()->stripe_customer_id);
        $this->assertSame(2, AuditLog::where('action', 'member_intake_conflict')->count());
    }

    public function test_validation_names_fields_without_values(): void
    {
        $response = $this->send($this->payload(['email' => 'not-an-email', 'npn' => 'ABC', 'license_expires_at' => '12/31/2027', 'license_state' => 'Atlantis', 'stripe_customer_id' => 'nope']))
            ->assertUnprocessable();

        $this->assertEqualsCanonicalizing(['email', 'npn', 'license_expires_at', 'license_state', 'stripe_customer_id'], $response->json('fields'));
        $this->assertStringNotContainsString('not-an-email', $response->getContent());
        $this->assertStringNotContainsString('Atlantis', $response->getContent());

        $this->send('{not json')->assertUnprocessable()->assertJsonPath('fields', []);
        $this->assertSame(0, IntakeSubmission::count());
    }

    public function test_the_switch_is_off_by_default_and_answers_404(): void
    {
        config(['portal.intake.enabled' => false]);

        $this->send($this->payload())->assertNotFound();

        $this->assertSame(0, IntakeSubmission::count());
    }

    public function test_requests_are_rate_limited(): void
    {
        config(['portal.intake.requests_per_minute' => 2]);

        $this->send($this->payload(), secret: 'wrong')->assertUnauthorized();
        $this->send($this->payload(), secret: 'wrong')->assertUnauthorized();
        $this->send($this->payload(), secret: 'wrong')->assertStatus(429);
    }

    public function test_nothing_about_the_member_reaches_the_log(): void
    {
        Log::spy();

        $this->send($this->payload())->assertOk();

        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }
}
