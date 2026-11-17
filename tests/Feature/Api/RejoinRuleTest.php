<?php

namespace Tests\Feature\Api;

use App\Enums\PaymentStatus;
use App\Enums\PersonType;
use App\Enums\Role;
use App\Livewire\AgencyRegistration\Wizard;
use App\Models\AuditLog;
use App\Models\IntakeSubmission;
use App\Models\MemberProfile;
use App\Models\User;
use App\Notifications\Staff\StaffAlert;
use App\Services\Agencies\AgencyRosterChanges;
use App\Services\Memberships\RejoinRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * The 24-month rule (client's answer): properx.org reports a canceled individual subscription
 * (POST /api/intake/cancellation); the member keeps access until the paid period ends, and may
 * not apply again for 24 months unless staff lift the wait.
 */
class RejoinRuleTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    private const SECRET = 'test-intake-secret';

    private MemberProfile $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->apiService();
        config(['portal.intake.enabled' => true, 'portal.intake.secret' => self::SECRET, 'portal.rejoin.wait_months' => 24, 'portal.member_area.enabled' => true]);

        $user = User::factory()->withTwoFactor()->create(['email' => 'avery@example.test'])->assignRole(Role::Member->value);
        $this->member = MemberProfile::factory()->for($user)->create([
            'npn' => '12345678',
            'payment_status' => PaymentStatus::Active,
            'paid_through_at' => now()->addMonths(5),
            'stripe_customer_id' => 'cus_Avery',
            'stripe_subscription_id' => 'sub_Avery',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(string $path, array $payload): TestResponse
    {
        $body = json_encode($payload);
        $timestamp = now()->getTimestamp();

        return $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET),
        ], $body);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function cancel(array $overrides = []): TestResponse
    {
        return $this->send('/api/intake/cancellation', array_merge([
            'entry_id' => '501',
            'email' => 'AVERY@example.test',
            'canceled_at' => now()->toIso8601String(),
            'stripe_customer_id' => 'cus_Avery',
            'stripe_subscription_id' => 'sub_Avery',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function apply(array $overrides = []): TestResponse
    {
        return $this->send('/api/intake/individual', array_merge([
            'entry_id' => '777',
            'email' => 'avery@example.test',
            'first_name' => 'Avery',
            'last_name' => 'Stone',
            'npn' => '12345678',
            'license_expires_at' => today()->addYear()->toDateString(),
            'stripe_customer_id' => 'cus_New',
            'stripe_subscription_id' => 'sub_New',
        ], $overrides));
    }

    // The cancellation

    public function test_a_cancellation_marks_the_member_with_the_date_and_access_lasts_until_the_paid_period_ends(): void
    {
        $this->cancel()->assertOk()->assertExactJson(['outcome' => 'canceled', 'member_profile_id' => $this->member->id]);

        $member = $this->member->fresh();
        $this->assertSame(PaymentStatus::Canceled, $member->payment_status);
        $this->assertSame(now()->toDateString(), $member->membership_canceled_at->toDateString());
        $this->assertFalse($member->hasRestrictedMembership());

        $this->assertSame([
            'source' => 'properx.org', 'entry_id' => '501', 'canceled_on' => now()->toDateString(),
            'access_until' => $member->paid_through_at->toDateString(), 'eligible_on' => now()->addMonths(24)->toDateString(),
        ], AuditLog::query()->where('action', 'member_membership_canceled')->sole()->details);

        // Once the paid period is over, access closes.
        $this->travelTo($member->paid_through_at->copy()->addMinute());
        $this->assertTrue($member->fresh()->hasRestrictedMembership());
    }

    public function test_a_repeated_cancellation_is_answered_from_the_first_and_unknown_people_are_not_found(): void
    {
        $this->cancel()->assertJsonPath('outcome', 'canceled');
        $this->travel(2)->days();
        $this->cancel()->assertJsonPath('outcome', 'canceled');

        $this->assertSame(1, AuditLog::query()->where('action', 'member_membership_canceled')->count());
        $this->assertSame(today()->subDays(2)->toDateString(), $this->member->fresh()->membership_canceled_at->toDateString());

        $this->cancel(['entry_id' => '502', 'email' => 'nobody@example.test', 'stripe_customer_id' => null, 'stripe_subscription_id' => null])
            ->assertOk()->assertJsonPath('outcome', 'not_found');
        $this->assertSame(2, IntakeSubmission::query()->where('source', 'properx.org/cancellation')->count());
    }

    public function test_the_cancellation_needs_the_signature_and_valid_fields(): void
    {
        $this->call('POST', '/api/intake/cancellation', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}')->assertUnauthorized();
        $this->cancel(['canceled_at' => 'soon', 'stripe_subscription_id' => 'x'])->assertUnprocessable()->assertJsonPath('fields', ['canceled_at', 'stripe_subscription_id']);

        config(['portal.intake.enabled' => false]);
        $this->cancel()->assertNotFound();
        $this->assertNull($this->member->fresh()->membership_canceled_at);
    }

    // The rule

    public function test_an_application_within_24_months_is_blocked_and_staff_are_told(): void
    {
        Notification::fake();
        $coordinator = $this->staff(Role::MembershipCoordinator);
        $this->cancel();

        // Under another email, found by the NPN.
        $this->apply(['email' => 'avery.new@example.test'])->assertOk()
            ->assertJsonPath('outcome', 'blocked')
            ->assertJsonPath('eligible_on', now()->addMonths(24)->toDateString());

        $this->assertFalse(User::query()->where('email', 'avery.new@example.test')->exists());
        $this->assertSame('sub_Avery', $this->member->fresh()->stripe_subscription_id);
        $this->assertSame(['context' => 'intake', 'canceled_on' => now()->toDateString(), 'eligible_on' => now()->addMonths(24)->toDateString()], AuditLog::query()->where('action', 'member_rejoin_blocked')->sole()->details);
        Notification::assertSentTo($coordinator, StaffAlert::class, fn (StaffAlert $alert) => $alert->alertKind === 'rejoin_blocked');

        // After 24 months the same application goes through.
        $this->travelTo(now()->addMonths(24)->addDay());
        $this->apply(['entry_id' => '778'])->assertJsonPath('outcome', 'linked');
    }

    public function test_staff_lift_the_wait_with_a_reason(): void
    {
        $this->cancel();
        $coordinator = $this->staff(Role::MembershipCoordinator);

        $detail = collect($this->actingAs($coordinator)->getJson("/nova-api/member-profiles/{$this->member->id}")->assertOk()->json('resource.fields'))->pluck('value', 'name');
        $this->assertSame(now()->toFormattedDateString(), $detail['Canceled On']);
        $this->assertSame('Eligible again on '.now()->addMonths(24)->toFormattedDateString(), $detail['Rejoin']);

        $this->postJson('/nova-api/member-profiles/action?action=lift-rejoin-wait', ['resources' => (string) $this->member->id, 'reason' => 'nonsense'])->assertUnprocessable();
        $this->postJson('/nova-api/member-profiles/action?action=lift-rejoin-wait', ['resources' => (string) $this->member->id, 'reason' => 'approved_exception'])->assertOk();

        $this->assertNull(RejoinRule::blockedUntil($this->member->fresh()));
        $this->assertSame(['reason' => 'approved_exception', 'canceled_on' => now()->toDateString()], AuditLog::query()->where('action', 'member_rejoin_block_lifted')->sole()->details);
        $this->apply()->assertJsonPath('outcome', 'linked');

        // A later cancellation starts a new wait.
        $this->travel(1)->days();
        $this->cancel(['entry_id' => '503', 'canceled_at' => now()->toIso8601String()]);
        $this->assertNotNull(RejoinRule::blockedUntil($this->member->fresh()));

        // Compliance staff cannot lift it.
        $this->actingAs($this->staff(Role::ComplianceSpecialist))
            ->postJson('/nova-api/member-profiles/action?action=lift-rejoin-wait', ['resources' => (string) $this->member->id, 'reason' => 'approved_exception'])->assertForbidden();
    }

    public function test_the_agency_wizard_refuses_a_recently_canceled_member_and_records_it(): void
    {
        $this->cancel();
        config(['portal.registration.enabled' => true]);
        $people = Livewire::test(Wizard::class)->instance()->people;

        $errors = $people->rowErrors(['type' => 'licensed_producer', 'first_name' => 'Avery', 'last_name' => 'Stone', 'email' => 'other@example.test', 'npn' => '12345678', 'license_state' => 'TX']);
        $this->assertSame(RejoinRule::MESSAGE, $errors['npn']);

        $errors = $people->rowErrors(['type' => 'agency_employee', 'first_name' => 'Avery', 'last_name' => 'Stone', 'email' => 'avery@example.test', 'npn' => '', 'license_state' => '']);
        $this->assertStringContainsString('Please contact ProPerX', $errors['email']);

        // Once a day per person and place, however often the form is checked.
        $this->assertSame(1, AuditLog::query()->where('action', 'member_rejoin_blocked')->count());
        $this->assertSame('agency_enrollment', AuditLog::query()->where('action', 'member_rejoin_blocked')->sole()->details['context']);
        Cache::flush();
    }

    public function test_move_to_an_agency_is_refused_during_the_wait(): void
    {
        $this->cancel();
        $problem = AgencyRosterChanges::moveProblem($this->member->fresh(), PersonType::LicensedProducer);

        $this->assertStringContainsString('may join again on '.now()->addMonths(24)->toFormattedDateString(), (string) $problem);
        $this->assertStringContainsString('Lift rejoin wait', (string) $problem);
    }
}
