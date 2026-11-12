<?php

namespace Tests\Feature\Agencies;

use App\Enums\AgencyStatus;
use App\Enums\MemberStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\MemberProfile;
use App\Notifications\AgencyMembershipCanceled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * Agencies and their people are canceled or deactivated, never deleted: Nova's Delete is closed to
 * everyone, the Executive Director included, for agencies with people or a payment, and for agency
 * people and paid members; "Cancel agency membership" ends the Stripe subscription instead.
 */
class AgencyCancellationTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        config(['services.stripe.secret' => 'sk_test_fake']);
        $this->agency = $this->agency(3, ['status' => AgencyStatus::Active, 'paid_through_at' => now()->addMonths(10)]);
        $this->agency->forceFill(['stripe_customer_id' => 'cus_agency', 'stripe_subscription_id' => 'sub_agency'])->saveQuietly();
    }

    private function cancel(string $timing, array $fields = [], ?Role $role = Role::ExecutiveDirector): TestResponse
    {
        return $this->actingAs($this->staff($role))->postJson('/nova-api/agencies/action?action=cancel-agency-membership', array_merge([
            'resources' => [$this->agency->id], 'reason' => 'agency_request', 'timing' => $timing,
        ], $fields));
    }

    // Delete is closed

    public function test_not_even_the_executive_director_deletes_an_agency_with_people_in_nova(): void
    {
        $director = $this->staff(Role::ExecutiveDirector);

        $this->actingAs($director)->deleteJson('/nova-api/agencies?resources[]='.$this->agency->id);

        $this->assertNotNull($this->agency->fresh());
        $this->assertSame(3, $this->agency->members()->count());
        $this->assertFalse($director->can('delete', $this->agency));
        $this->assertSame([], $this->stripe->requests);
    }

    public function test_agency_people_and_paid_members_are_not_deleted_in_nova(): void
    {
        $director = $this->staff(Role::ExecutiveDirector);
        $person = $this->agency->members()->where('agency_role', 'member')->first();

        $paid = $this->member();
        $paid->memberProfile->forceFill(['payment_status' => PaymentStatus::Active, 'stripe_subscription_id' => 'sub_member'])->save();
        $unpaid = $this->member();

        foreach ([$person, $paid->memberProfile] as $profile) {
            $this->assertFalse($director->can('delete', $profile));
            $this->assertFalse($director->can('delete', $profile->user));
            $this->actingAs($director)->deleteJson('/nova-api/member-profiles?resources[]='.$profile->id);
            $this->actingAs($director)->deleteJson('/nova-api/members?resources[]='.$profile->user_id);
            $this->assertNotNull($profile->fresh());
            $this->assertNotNull($profile->user->fresh());
        }

        // A member with no agency and nothing paid may still be removed (e.g. a duplicate).
        $this->assertTrue($director->can('delete', $unpaid->memberProfile));
        $this->assertTrue($director->can('delete', $unpaid));
    }

    // Cancel agency membership

    public function test_cancel_now_ends_the_subscription_and_makes_everyone_inactive(): void
    {
        Notification::fake();
        $this->stripe->on('DELETE', '/v1/subscriptions/sub_agency', ['id' => 'sub_agency', 'object' => 'subscription', 'status' => 'canceled']);

        $this->cancel('now')->assertOk()->assertJson(['message' => 'Membership canceled; the agency and its people are inactive.']);

        $agency = $this->agency->fresh();
        $this->assertSame(AgencyStatus::Inactive, $agency->status);
        $this->assertSame([MemberStatus::Inactive], $agency->members()->pluck('status')->unique()->values()->all());
        $this->assertTrue($agency->members()->first()->hasRestrictedMembership());
        $this->assertSame(3, MemberProfile::query()->where('agency_id', $agency->id)->count()); // nothing deleted

        $this->assertSame(['reason' => 'agency_request', 'timing' => 'now'], AuditLog::query()->where('action', 'agency_membership_canceled')->sole()->details);
        $this->assertDatabaseHas('audit_logs', ['action' => 'agency_payment_status_changed', 'resource_id' => $agency->id]);

        Notification::assertSentTo($agency->principal, AgencyMembershipCanceled::class, function (AgencyMembershipCanceled $notification) use ($agency) {
            $mail = $notification->toMail($agency->principal);

            return $notification->timing === 'now'
                && $mail->subject === 'Your agency’s ProPerX Alliance membership has been canceled'
                && ! str_contains(implode(' ', $mail->introLines), $agency->legal_name);
        });
    }

    public function test_cancel_at_the_period_end_keeps_access_until_then(): void
    {
        Notification::fake();
        $this->stripe->on('POST', '/v1/subscriptions/sub_agency', ['id' => 'sub_agency', 'object' => 'subscription', 'status' => 'active', 'cancel_at_period_end' => true]);

        $this->cancel('period_end')->assertOk();

        $this->assertSame(['cancel_at_period_end' => 'true'], $this->stripe->sent('POST', '/v1/subscriptions/sub_agency')[0]['params']);
        $agency = $this->agency->fresh();
        $this->assertSame(AgencyStatus::Active, $agency->status);
        $this->assertTrue($agency->cancel_at_period_end);
        $this->assertFalse($agency->members()->first()->hasRestrictedMembership());

        Notification::assertSentTo($agency->principal, AgencyMembershipCanceled::class, function (AgencyMembershipCanceled $notification) use ($agency) {
            return str_contains(implode(' ', $notification->toMail($agency->principal)->introLines), 'stays active until '.$agency->paid_through_at->format('F j, Y'));
        });
    }

    public function test_cancel_needs_a_reason_and_the_executive_director(): void
    {
        $this->cancel('now', ['reason' => ''])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->cancel('', [])->assertUnprocessable()->assertJsonValidationErrors('timing');
        $this->cancel('now', [], Role::MembershipCoordinator)->assertForbidden();

        $this->assertSame(AgencyStatus::Active, $this->agency->fresh()->status);
        $this->assertSame([], $this->stripe->requests);
    }

    public function test_nothing_changes_when_stripe_refuses(): void
    {
        Notification::fake();
        $this->stripe->on('DELETE', '/v1/subscriptions/sub_agency', ['error' => ['type' => 'invalid_request_error', 'message' => 'No such subscription']], 404);

        $this->cancel('now')->assertOk()->assertJsonStructure(['danger']);

        $this->assertSame(AgencyStatus::Active, $this->agency->fresh()->status);
        $this->assertFalse(AuditLog::query()->where('action', 'agency_membership_canceled')->exists());
        Notification::assertNothingSent();
    }
}
