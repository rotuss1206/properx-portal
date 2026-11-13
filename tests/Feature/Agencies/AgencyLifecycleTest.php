<?php

namespace Tests\Feature\Agencies;

use App\Enums\AgencyStatus;
use App\Enums\AgencyTier;
use App\Enums\MemberStatus;
use App\Enums\RateType;
use App\Enums\Role;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\AgencyPaymentFailed;
use App\Notifications\Member\MembershipExpired;
use App\Support\MembershipBanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Nova\Notifications\NovaNotification;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * An enrolled agency's subscription after the enrollment (Agency spec 6, 7): renewals, failed
 * payments, changes and the end of the subscription, in any order and repeated, and what they mean
 * for the agency's people (past due keeps access, inactive closes it).
 */
class AgencyLifecycleTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test_secret';

    private Agency $agency;

    private User $principal;

    private User $person;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->apiService();
        config([
            'services.stripe.webhook_secret' => self::WEBHOOK_SECRET,
            'portal.payments.payment_url' => 'https://pay.example.test/portal',
            'portal.member_area.enabled' => true,
        ]);
        $this->travelTo(CarbonImmutable::parse('2027-11-02 10:00', 'America/New_York'));

        $this->agency = $this->agency(3, [
            'stripe_customer_id' => 'cus_agency',
            'stripe_subscription_id' => 'sub_agency',
            'tier' => AgencyTier::Agency,
            'rate_type' => RateType::Founding,
            'status' => AgencyStatus::Active,
            'paid_through_at' => now()->addDay(),
        ]);
        $this->principal = $this->agency->principal;
        $this->person = $this->agency->members()->where('user_id', '!=', $this->principal->id)->firstOrFail()->user;
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function webhook(string $type, array $object, ?int $created = null, ?string $id = null): TestResponse
    {
        $payload = json_encode([
            'id' => $id ?? 'evt_'.Str::random(12), 'object' => 'event', 'type' => $type,
            'created' => $created ?? now()->getTimestamp(), 'data' => ['object' => $object],
        ]);
        // Stripe checks the signature against the real clock, not the travelled one.
        $timestamp = time();

        return $this->call('POST', route('webhooks.stripe'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", self::WEBHOOK_SECRET),
        ], $payload);
    }

    /**
     * A renewal invoice: no metadata needed, the customer is the agency's.
     *
     * @return array<string, mixed>
     */
    private function invoice(?int $periodEnd = null, array $extra = []): array
    {
        return $extra + [
            'id' => 'in_renewal', 'object' => 'invoice', 'customer' => 'cus_agency', 'amount_paid' => 47700, 'currency' => 'usd',
            'hosted_invoice_url' => 'https://invoice.stripe.test/i/renewal',
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_agency', 'metadata' => []]],
            'lines' => ['object' => 'list', 'data' => [['id' => 'il_1', 'object' => 'line_item', 'period' => ['start' => now()->getTimestamp(), 'end' => $periodEnd ?? now()->addYear()->getTimestamp()]]]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subscription(string $status, int $quantity = 3, string $lookupKey = 'agency_founding_2to9', bool $cancelAtPeriodEnd = false): array
    {
        return [
            'id' => 'sub_agency', 'object' => 'subscription', 'customer' => 'cus_agency', 'status' => $status,
            'cancel_at_period_end' => $cancelAtPeriodEnd, 'metadata' => [],
            'items' => ['object' => 'list', 'data' => [[
                'id' => 'si_1', 'object' => 'subscription_item', 'quantity' => $quantity,
                'current_period_end' => now()->addYear()->getTimestamp(),
                'price' => ['id' => 'price_x', 'object' => 'price', 'lookup_key' => $lookupKey],
            ]]],
        ];
    }

    private function signIn(User $user): void
    {
        $this->actingAs($user);
    }

    // Renewal

    public function test_a_renewal_moves_paid_through_forward_and_keeps_the_agency_active(): void
    {
        $end = now()->addYear()->addDay()->getTimestamp();

        $this->webhook('invoice.paid', $this->invoice($end))->assertOk()->assertJson(['outcome' => 'agency_paid']);

        $agency = $this->agency->fresh();
        $this->assertSame($end, $agency->paid_through_at->getTimestamp());
        $this->assertSame(AgencyStatus::Active, $agency->status);
        $this->assertNull($agency->payment_url);
    }

    public function test_paying_after_a_failure_makes_the_agency_active_again(): void
    {
        Notification::fake();
        $this->webhook('invoice.payment_failed', $this->invoice());
        $this->travel(1)->hours();

        $this->webhook('invoice.paid', $this->invoice())->assertJson(['outcome' => 'agency_paid']);

        $this->assertSame(AgencyStatus::Active, $this->agency->fresh()->status);
        $this->assertNull(MembershipBanner::for($this->principal->fresh()));
    }

    // Failed payment

    public function test_a_failed_payment_makes_the_agency_past_due_and_tells_the_principal_and_staff(): void
    {
        Notification::fake();
        $coordinator = $this->staff(Role::MembershipCoordinator);

        $this->webhook('invoice.payment_failed', $this->invoice())->assertOk()->assertJson(['outcome' => 'agency_past_due']);

        $agency = $this->agency->fresh();
        $this->assertSame(AgencyStatus::PastDue, $agency->status);
        $this->assertSame('https://invoice.stripe.test/i/renewal', $agency->payment_url);
        $this->assertSame(MemberStatus::Active, $this->person->memberProfile->fresh()->status);

        Notification::assertSentTo($this->principal, AgencyPaymentFailed::class, fn (AgencyPaymentFailed $notification) => $notification->payUrl === 'https://invoice.stripe.test/i/renewal');
        Notification::assertNotSentTo($this->person, AgencyPaymentFailed::class);
        Notification::assertSentTo($coordinator, NovaNotification::class);

        $this->assertDatabaseHas('audit_logs', ['action' => 'agency_payment_failed', 'resource_id' => $agency->id]);
        $change = AuditLog::query()->where('action', 'agency_payment_status_changed')->sole();
        $this->assertSame(['from' => 'active', 'to' => 'past_due'], $change->details);
    }

    public function test_the_email_has_a_pay_link_and_no_agency_details(): void
    {
        $mail = (new AgencyPaymentFailed('https://invoice.stripe.test/i/renewal'))->toMail($this->principal);

        $this->assertSame('Pay now', $mail->actionText);
        $this->assertSame('https://invoice.stripe.test/i/renewal', $mail->actionUrl);
        $rendered = (string) $mail->render();
        $this->assertStringNotContainsString($this->agency->legal_name, $rendered);
        $this->assertStringNotContainsString($this->principal->name, $rendered);

        $fallback = (new AgencyPaymentFailed(null))->toMail($this->principal);
        $this->assertNull($fallback->actionUrl);
    }

    public function test_a_second_failure_tells_nobody_again(): void
    {
        Notification::fake();

        $this->webhook('invoice.payment_failed', $this->invoice());
        $this->webhook('customer.subscription.updated', $this->subscription('past_due'))->assertJson(['outcome' => 'agency_updated']);
        $this->webhook('invoice.payment_failed', $this->invoice());

        Notification::assertSentToTimes($this->principal, AgencyPaymentFailed::class, 1);
        $this->assertSame(AgencyStatus::PastDue, $this->agency->fresh()->status);
    }

    // Past due: access stays, a banner

    public function test_past_due_keeps_access_and_shows_the_banner_with_a_pay_button_to_the_principal_only(): void
    {
        Notification::fake();
        $this->webhook('invoice.payment_failed', $this->invoice());

        $this->assertFalse($this->person->memberProfile->fresh()->hasRestrictedMembership());

        $this->signIn($this->principal->fresh());
        $this->get(route('member.dashboard'))
            ->assertOk()
            ->assertSee('Your agency&#039;s membership payment is overdue.', false)
            ->assertSee('Pay now')
            ->assertSee('https://invoice.stripe.test/i/renewal');

        $this->signIn($this->person->fresh());
        $this->get(route('member.dashboard'))
            ->assertOk()
            ->assertSee('Your agency&#039;s membership payment is overdue.', false)
            ->assertDontSee('Pay now')
            ->assertDontSee('https://invoice.stripe.test/i/renewal')
            ->assertSee('Payment overdue')
            ->assertDontSee('Renews ');

        $this->get(route('member.certificate'))->assertOk()->assertDontSee('not available');
    }

    // Subscription changes

    public function test_a_subscription_update_brings_the_quantity_the_price_and_the_cancellation(): void
    {
        $this->webhook('customer.subscription.updated', $this->subscription('active', 12, 'agency_standard_10to49', true))
            ->assertJson(['outcome' => 'agency_updated']);

        $agency = $this->agency->fresh();
        $this->assertSame(12, $agency->billed_headcount);
        $this->assertTrue($agency->cancel_at_period_end);
        $this->assertSame(AgencyTier::AgencyPlus, $agency->tier);
        $this->assertSame(RateType::Standard, $agency->rate_type);
        // Stripe starts the next period before it is paid: only invoice.paid moves the paid period.
        $this->assertSame(now()->addDay()->getTimestamp(), $agency->paid_through_at->getTimestamp());
        $this->assertSame(AgencyStatus::Active, $agency->status);

        $price = AuditLog::query()->where('action', 'agency_price_changed_in_stripe')->sole();
        $this->assertSame(['from' => ['agency_2to9', 'founding'], 'to' => ['agency_10to49', 'standard']], $price->details);
    }

    public function test_an_unknown_price_changes_neither_tier_nor_rate(): void
    {
        $this->webhook('customer.subscription.updated', $this->subscription('active', 4, 'partner_custom'));

        $agency = $this->agency->fresh();
        $this->assertSame(4, $agency->billed_headcount);
        $this->assertSame(AgencyTier::Agency, $agency->tier);
        $this->assertSame(RateType::Founding, $agency->rate_type);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'agency_price_changed_in_stripe']);
    }

    // End of the subscription: inactive

    public function test_a_deleted_subscription_makes_the_agency_and_its_people_inactive(): void
    {
        $this->webhook('customer.subscription.deleted', $this->subscription('canceled'))->assertJson(['outcome' => 'agency_inactive']);

        $agency = $this->agency->fresh();
        $this->assertSame(AgencyStatus::Inactive, $agency->status);
        $this->assertTrue($agency->members()->get()->every(fn ($profile) => $profile->status === MemberStatus::Inactive));

        $profile = $this->person->memberProfile->fresh();
        $this->assertTrue($profile->hasRestrictedMembership());

        $this->get(route('certificates.verify', $profile->certificate_code))
            ->assertOk()
            ->assertSee('Membership is not active')
            ->assertDontSee('Valid certificate');

        $this->signIn($this->person->fresh());
        $this->get(route('member.dashboard'))
            ->assertOk()
            ->assertSee('membership has ended')
            ->assertSee('Ended '.$agency->paid_through_at->format('j M Y'))
            ->assertDontSee('Renews ')
            ->assertDontSee('Renew now');
        $this->get(route('certificates.open', $profile))->assertUnprocessable();

        $this->signIn($this->principal->fresh());
        $this->get(route('member.dashboard'))->assertOk()->assertSee('Renew now');
    }

    public function test_coming_back_to_active_restores_everything(): void
    {
        $this->webhook('customer.subscription.deleted', $this->subscription('canceled'));
        $this->travel(1)->hours();

        $this->webhook('customer.subscription.updated', $this->subscription('active'))->assertJson(['outcome' => 'agency_updated']);

        $this->assertSame(AgencyStatus::Active, $this->agency->fresh()->status);
        $profile = $this->person->memberProfile->fresh();
        $this->assertSame(MemberStatus::Active, $profile->status);
        $this->assertFalse($profile->hasRestrictedMembership());

        $this->get(route('certificates.verify', $profile->certificate_code))->assertSee('Valid certificate');
    }

    // Order and repeats

    public function test_an_older_event_arriving_later_changes_no_status(): void
    {
        Notification::fake();
        config(['portal.renewals.expiry_emails_enabled' => true]);
        $failedAt = now()->getTimestamp();

        $this->webhook('customer.subscription.deleted', $this->subscription('canceled'), $failedAt + 60);
        $this->webhook('invoice.payment_failed', $this->invoice(), $failedAt)->assertJson(['outcome' => 'agency_past_due']);
        $this->webhook('customer.subscription.updated', $this->subscription('active'), $failedAt)->assertJson(['outcome' => 'agency_stale']);

        // The ended membership stays ended; nobody is asked to pay an invoice of it. The principal
        // hears once that it expired.
        $this->assertSame(AgencyStatus::Inactive, $this->agency->fresh()->status);
        Notification::assertNotSentTo($this->principal, AgencyPaymentFailed::class);
        Notification::assertSentToTimes($this->principal, MembershipExpired::class, 1);
    }

    public function test_a_failure_reported_just_before_the_subscriptions_update_still_brings_the_invoice_link(): void
    {
        // As Stripe sent them live: the subscription's update (past due) a second after the failure, arriving first.
        Notification::fake();
        $at = now()->getTimestamp();

        $this->webhook('customer.subscription.updated', $this->subscription('past_due'), $at + 1)->assertJson(['outcome' => 'agency_updated']);
        Notification::assertNothingSentTo($this->principal);

        $this->webhook('invoice.payment_failed', $this->invoice(), $at)->assertJson(['outcome' => 'agency_past_due']);

        $agency = $this->agency->fresh();
        $this->assertSame(AgencyStatus::PastDue, $agency->status);
        $this->assertSame('https://invoice.stripe.test/i/renewal', $agency->payment_url);
        Notification::assertSentTo($this->principal, AgencyPaymentFailed::class, fn (AgencyPaymentFailed $notification) => $notification->payUrl === 'https://invoice.stripe.test/i/renewal');
    }

    public function test_a_failure_reported_after_a_later_payment_changes_nothing(): void
    {
        Notification::fake();
        $at = now()->getTimestamp();

        $this->webhook('invoice.paid', $this->invoice(), $at + 60)->assertJson(['outcome' => 'agency_paid']);
        $this->webhook('invoice.payment_failed', $this->invoice(), $at)->assertJson(['outcome' => 'agency_stale']);

        $this->assertSame(AgencyStatus::Active, $this->agency->fresh()->status);
        $this->assertNull($this->agency->fresh()->payment_url);
        Notification::assertNothingSent();
    }

    public function test_an_older_paid_invoice_still_extends_the_period_but_not_the_status(): void
    {
        $now = now()->getTimestamp();
        $this->webhook('customer.subscription.deleted', $this->subscription('canceled'), $now + 60);

        $end = now()->addYears(2)->getTimestamp();
        $this->webhook('invoice.paid', $this->invoice($end), $now)->assertJson(['outcome' => 'agency_stale']);

        $agency = $this->agency->fresh();
        $this->assertSame($end, $agency->paid_through_at->getTimestamp());
        $this->assertSame(AgencyStatus::Inactive, $agency->status);
    }

    public function test_the_same_event_twice_is_applied_once(): void
    {
        Notification::fake();

        $this->webhook('invoice.payment_failed', $this->invoice(), id: 'evt_same')->assertJson(['outcome' => 'agency_past_due']);
        $this->webhook('invoice.payment_failed', $this->invoice(), id: 'evt_same')->assertJson(['outcome' => 'duplicate']);

        $this->assertSame(1, AuditLog::query()->where('action', 'agency_payment_failed')->count());
        Notification::assertSentToTimes($this->principal, AgencyPaymentFailed::class, 1);
    }

    // The principal's own profile

    public function test_the_principals_own_profile_is_never_touched(): void
    {
        Notification::fake();
        $profile = $this->principal->memberProfile;
        $profile->forceFill(['stripe_customer_id' => null])->saveQuietly();
        $before = $profile->fresh()->only(['payment_status', 'paid_through_at', 'payment_status_updated_at', 'stripe_customer_id']);

        $this->webhook('invoice.payment_failed', $this->invoice(null, ['customer_email' => $this->principal->email]));
        $this->webhook('customer.subscription.updated', $this->subscription('past_due'));
        $this->webhook('customer.subscription.deleted', $this->subscription('canceled'));

        $this->assertSame($before, $profile->fresh()->only(['payment_status', 'paid_through_at', 'payment_status_updated_at', 'stripe_customer_id']));
    }
}
