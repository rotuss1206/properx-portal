<?php

namespace Tests\Feature\Agencies;

use App\Enums\AgencyStatus;
use App\Enums\AgencyTier;
use App\Enums\MemberStatus;
use App\Enums\PaymentStatus;
use App\Enums\RateType;
use App\Enums\ReminderType;
use App\Enums\Role;
use App\Member\NotificationFeed;
use App\Models\Agency;
use App\Models\ReminderLog;
use App\Models\User;
use App\Notifications\AgencyMembershipCanceled;
use App\Notifications\Member\AgencyMembershipEnded;
use App\Notifications\Member\MembershipExpired;
use App\Notifications\Member\PaymentOverdueReminder;
use App\Notifications\Member\RenewalReminder;
use App\Support\MembershipBanner;
use App\Support\RenewalDays;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * Renewals (H1-H3): reminders 14, 7 and 1 day before, by email and in the bell, once each; after a
 * failed renewal the grace period (14 days, or none), its reminders and the end; the subscription's
 * end; restoring everything when the payment comes later.
 */
class RenewalsTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test_secret';

    private Agency $agency;

    private User $principal;

    private User $person;

    private User $individual;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->apiService();
        config([
            'services.stripe.webhook_secret' => self::WEBHOOK_SECRET,
            'services.stripe.secret' => 'sk_test_fake',
            'portal.member_area.enabled' => true,
            'portal.payments.grace_days' => 14,
            'portal.renewals.reminder_days' => [14, 7, 1],
            'portal.renewals.grace_reminder_days' => [7, 1],
        ]);
        $this->travelTo(CarbonImmutable::parse('2027-03-01 08:00', 'America/New_York'));

        $this->agency = $this->agency(3, [
            'tier' => AgencyTier::Agency, 'rate_type' => RateType::Founding, 'status' => AgencyStatus::Active,
            'paid_through_at' => now()->addDays(14)->setTime(12, 0),
        ]);
        $this->agency->forceFill(['stripe_customer_id' => 'cus_agency', 'stripe_subscription_id' => 'sub_agency', 'billed_headcount' => 3])->saveQuietly();
        $this->principal = $this->agency->principal;
        $this->person = $this->agency->members()->where('user_id', '!=', $this->principal->id)->firstOrFail()->user;

        $this->individual = $this->member();
        $this->individual->memberProfile->forceFill([
            'stripe_customer_id' => 'cus_member', 'stripe_subscription_id' => 'sub_member',
            'payment_status' => PaymentStatus::Active, 'paid_through_at' => now()->addDays(14)->setTime(12, 0),
        ])->save();

        $this->answerStripe();
    }

    private function answerStripe(bool $cancels = false): void
    {
        foreach (['agency' => [3, 15900], 'member' => [1, 19900]] as $who => [$quantity, $unit]) {
            $this->stripe->on('GET', "/v1/subscriptions/sub_{$who}", fn () => [
                'id' => "sub_{$who}", 'object' => 'subscription', 'customer' => "cus_{$who}", 'status' => 'active', 'currency' => 'usd', 'cancel_at_period_end' => $cancels,
                'default_payment_method' => ['id' => 'pm_1', 'object' => 'payment_method', 'type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 8, 'exp_year' => 2030]],
                'items' => ['object' => 'list', 'data' => [['id' => 'si_1', 'object' => 'subscription_item', 'quantity' => $quantity, 'current_period_end' => now()->addDays(14)->getTimestamp(), 'price' => ['id' => 'price_1', 'object' => 'price', 'unit_amount' => $unit, 'recurring' => ['interval' => 'year'], 'product' => ['id' => 'prod_1', 'object' => 'product', 'name' => 'Membership']]]]],
            ]);
        }

        $this->stripe->on('GET', '/v1/invoices', ['object' => 'list', 'data' => []]);
    }

    private function webhook(string $type, array $object): TestResponse
    {
        $payload = json_encode(['id' => 'evt_'.Str::random(12), 'object' => 'event', 'type' => $type, 'created' => now()->getTimestamp(), 'data' => ['object' => $object]]);
        $timestamp = time();

        return $this->call('POST', route('webhooks.stripe'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", self::WEBHOOK_SECRET),
        ], $payload);
    }

    private function invoice(string $customer, array $extra = []): array
    {
        $who = Str::after($customer, 'cus_');

        return $extra + [
            'id' => 'in_'.$who, 'object' => 'invoice', 'customer' => $customer, 'amount_paid' => 47700, 'currency' => 'usd',
            'hosted_invoice_url' => 'https://invoice.stripe.test/i/'.$who,
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_'.$who, 'metadata' => []]],
            'lines' => ['object' => 'list', 'data' => [['id' => 'il_1', 'object' => 'line_item', 'period' => ['start' => now()->getTimestamp(), 'end' => now()->addYear()->getTimestamp()]]]],
        ];
    }

    private function remind(): void
    {
        $this->artisan('app:send-renewal-reminders')->assertSuccessful();
    }

    // Before the renewal

    public function test_reminders_go_14_7_and_1_day_before_once_each(): void
    {
        Notification::fake();

        $this->remind();
        $this->remind(); // the same day again: nothing new

        Notification::assertSentToTimes($this->principal, RenewalReminder::class, 1);
        Notification::assertSentTo($this->principal, RenewalReminder::class, function (RenewalReminder $reminder, array $channels) {
            $mail = $reminder->toMail($this->principal);
            $body = implode("\n", array_map('strval', $mail->introLines));

            return $reminder->kind === RenewalReminder::AGENCY && $reminder->days === 14 && $channels === ['database', 'mail']
                && $mail->subject === 'Your agency’s ProPerX Alliance membership renews in 14 days'
                && str_contains($body, '| People | 3 |') && str_contains($body, '| Tier | Agency |')
                && str_contains($body, '| Amount | $477.00 a year |') && str_contains($body, '| Payment method | Visa ending 4242 |')
                && $mail->actionUrl === route('member.billing');
        });
        Notification::assertSentTo($this->individual, RenewalReminder::class, fn (RenewalReminder $reminder) => $reminder->kind === RenewalReminder::INDIVIDUAL
            && str_contains(implode("\n", array_map('strval', $reminder->toMail($this->individual)->introLines)), '| Amount | $199.00 a year |'));
        Notification::assertNotSentTo($this->person, RenewalReminder::class);

        // Ten days left: the 14-day reminder was sent, the 7-day one is not due yet.
        $this->travel(4)->days();
        $this->remind();
        Notification::assertSentToTimes($this->principal, RenewalReminder::class, 1);

        $this->travel(3)->days(); // 7 days left
        $this->remind();
        $this->travel(6)->days(); // 1 day left
        $this->remind();
        $this->remind();

        Notification::assertSentToTimes($this->principal, RenewalReminder::class, 3);
        Notification::assertSentToTimes($this->individual, RenewalReminder::class, 3);
        $this->assertEqualsCanonicalizing([ReminderType::RenewalDays14, ReminderType::RenewalDays7, ReminderType::RenewalDays1],
            ReminderLog::query()->where('user_id', $this->principal->id)->pluck('reminder_type')->all());
    }

    public function test_the_days_come_from_config_and_the_bell_shows_the_reminder(): void
    {
        config(['portal.renewals.reminder_days' => [30]]);
        $this->agency->forceFill(['paid_through_at' => now()->addDays(25)])->saveQuietly();

        $this->remind();

        $this->assertSame(ReminderType::RenewalDays30, ReminderLog::query()->where('user_id', $this->principal->id)->sole()->reminder_type);
        $this->assertSame('Renewal reminder — 25 days', NotificationFeed::latest($this->principal->fresh())->first()['title']);
        $this->assertSame('30 days', RenewalDays::phrase());
        config(['portal.renewals.reminder_days' => [14, 7, 1]]);
        $this->assertSame('14, 7 and 1 day', RenewalDays::phrase());
    }

    public function test_no_reminder_for_a_membership_that_ends_with_its_period_or_without_a_subscription(): void
    {
        Notification::fake();
        $this->agency->forceFill(['cancel_at_period_end' => true])->saveQuietly();
        $this->individual->memberProfile->forceFill(['stripe_customer_id' => null])->save();

        $this->remind();

        Notification::assertNothingSent();
    }

    // A failed renewal, with a grace period

    public function test_past_due_keeps_access_for_14_days_then_expires_and_a_payment_restores_it(): void
    {
        Notification::fake();
        $this->webhook('invoice.payment_failed', $this->invoice('cus_agency'))->assertOk()->assertJson(['outcome' => 'agency_past_due']);

        $agency = $this->agency->fresh();
        $this->assertSame(AgencyStatus::PastDue, $agency->status);
        $this->assertSame(now()->getTimestamp(), $agency->past_due_since->getTimestamp());
        $this->assertFalse($this->person->memberProfile->fresh()->hasRestrictedMembership());
        $until = now()->addDays(14)->toFormattedDateString();
        $this->assertStringContainsString("your access continues until {$until}", MembershipBanner::for($this->person->fresh())['message']);
        $this->assertStringContainsString("Please pay by {$until}", MembershipBanner::for($this->principal->fresh())['message']);

        // Seven days left: a reminder; again the same day: nothing.
        $this->travel(7)->days();
        $this->artisan('app:expire-memberships')->assertSuccessful();
        $this->artisan('app:expire-memberships')->assertSuccessful();
        Notification::assertSentToTimes($this->principal, PaymentOverdueReminder::class, 1);
        $this->assertSame(AgencyStatus::PastDue, $this->agency->fresh()->status);

        // The grace period is over: inactive, the principal and the people told once.
        $this->travel(7)->days();
        $this->travel(1)->minutes();
        $this->artisan('app:expire-memberships')->assertSuccessful();
        $this->artisan('app:expire-memberships')->assertSuccessful();

        $agency = $this->agency->fresh();
        $this->assertSame(AgencyStatus::Inactive, $agency->status);
        $this->assertTrue($this->person->memberProfile->fresh()->hasRestrictedMembership());
        Notification::assertSentToTimes($this->principal, MembershipExpired::class, 1);
        Notification::assertSentToTimes($this->person, AgencyMembershipEnded::class, 1);
        Notification::assertNotSentTo($this->principal, AgencyMembershipEnded::class);

        // Stripe still says "past_due": that does not open access again.
        $this->webhook('customer.subscription.updated', ['id' => 'sub_agency', 'object' => 'subscription', 'customer' => 'cus_agency', 'status' => 'past_due', 'items' => ['object' => 'list', 'data' => []]])->assertOk();
        $this->assertSame(AgencyStatus::Inactive, $this->agency->fresh()->status);

        // Paying later restores everything.
        $this->webhook('invoice.paid', $this->invoice('cus_agency'))->assertOk();
        $agency = $this->agency->fresh();
        $this->assertSame(AgencyStatus::Active, $agency->status);
        $this->assertNull($agency->past_due_since);
        $this->assertSame([MemberStatus::Active], $agency->members()->pluck('status')->unique()->values()->all());
        $this->assertFalse($this->person->memberProfile->fresh()->hasRestrictedMembership());
    }

    public function test_with_no_grace_period_the_membership_expires_at_once(): void
    {
        Notification::fake();
        config(['portal.payments.grace_days' => 0]);

        $this->webhook('invoice.payment_failed', $this->invoice('cus_agency'))->assertOk()->assertJson(['outcome' => 'agency_inactive']);

        $this->assertSame(AgencyStatus::Inactive, $this->agency->fresh()->status);
        $this->assertTrue($this->person->memberProfile->fresh()->hasRestrictedMembership());
        Notification::assertSentToTimes($this->principal, MembershipExpired::class, 1);
        Notification::assertSentToTimes($this->person, AgencyMembershipEnded::class, 1);

        // An individual member too.
        $this->webhook('invoice.payment_failed', $this->invoice('cus_member'))->assertOk();
        $profile = $this->individual->memberProfile->fresh();
        $this->assertSame(PaymentStatus::PastDue, $profile->payment_status);
        $this->assertTrue($profile->hasRestrictedMembership());
        Notification::assertSentToTimes($this->individual, MembershipExpired::class, 1);
    }

    public function test_an_individual_member_expires_after_the_grace_period_and_paying_restores_access(): void
    {
        Notification::fake();
        $this->webhook('invoice.payment_failed', $this->invoice('cus_member'))->assertOk();
        $this->assertFalse($this->individual->memberProfile->fresh()->hasRestrictedMembership());

        $this->travel(13)->days();
        $this->artisan('app:expire-memberships');
        Notification::assertSentToTimes($this->individual, PaymentOverdueReminder::class, 1);
        Notification::assertNotSentTo($this->individual, MembershipExpired::class);

        $this->travel(1)->days();
        $this->travel(1)->minutes();
        $this->artisan('app:expire-memberships');
        $this->artisan('app:expire-memberships');
        $this->assertTrue($this->individual->memberProfile->fresh()->hasRestrictedMembership());
        Notification::assertSentToTimes($this->individual, MembershipExpired::class, 1);

        $this->webhook('invoice.paid', $this->invoice('cus_member'))->assertOk();
        $this->assertFalse($this->individual->memberProfile->fresh()->hasRestrictedMembership());
    }

    // The subscription's end

    public function test_the_end_of_a_subscription_sends_expired_once(): void
    {
        Notification::fake();
        $deleted = fn (string $who) => ['id' => "sub_{$who}", 'object' => 'subscription', 'customer' => "cus_{$who}", 'status' => 'canceled', 'items' => ['object' => 'list', 'data' => []]];

        $this->webhook('customer.subscription.deleted', $deleted('agency'))->assertOk();
        $this->webhook('customer.subscription.deleted', $deleted('agency'))->assertOk();
        $this->webhook('customer.subscription.deleted', $deleted('member'))->assertOk();

        $this->assertSame(AgencyStatus::Inactive, $this->agency->fresh()->status);
        Notification::assertSentToTimes($this->principal, MembershipExpired::class, 1);
        Notification::assertSentToTimes($this->person, AgencyMembershipEnded::class, 1);
        Notification::assertSentToTimes($this->individual, MembershipExpired::class, 1);
    }

    public function test_cancel_now_is_not_followed_by_a_second_email_to_the_principal(): void
    {
        Notification::fake();
        $this->stripe->on('DELETE', '/v1/subscriptions/sub_agency', ['id' => 'sub_agency', 'object' => 'subscription', 'status' => 'canceled']);

        $this->actingAs($this->staff(Role::ExecutiveDirector))
            ->postJson('/nova-api/agencies/action?action=cancel-agency-membership', ['resources' => [$this->agency->id], 'reason' => 'agency_request', 'timing' => 'now'])
            ->assertOk();
        auth()->logout();
        $this->webhook('customer.subscription.deleted', ['id' => 'sub_agency', 'object' => 'subscription', 'customer' => 'cus_agency', 'status' => 'canceled', 'items' => ['object' => 'list', 'data' => []]])->assertOk();

        Notification::assertSentToTimes($this->principal, AgencyMembershipCanceled::class, 1);
        Notification::assertNotSentTo($this->principal, MembershipExpired::class);
        Notification::assertSentToTimes($this->person, AgencyMembershipEnded::class, 1);
    }
}
