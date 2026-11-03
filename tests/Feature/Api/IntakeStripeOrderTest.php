<?php

namespace Tests\Feature\Api;

use App\Enums\PaymentStatus;
use App\Jobs\SyncStripeSubscription;
use App\Models\AuditLog;
use App\Models\MemberProfile;
use App\Models\StripeEvent;
use App\Models\User;
use App\Services\ServiceAccount;
use App\Services\Stripe\StripeWebhookHandler;
use App\Services\Stripe\SubscriptionReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * Stripe's events and the paid application reach the portal in either order; the member ends up
 * with the same paid period and status. A queued job also reads the subscription from Stripe.
 */
class IntakeStripeOrderTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    private const INTAKE_SECRET = 'test-intake-secret';

    private const WEBHOOK_SECRET = 'whsec_test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->apiService();
        config([
            'portal.intake.enabled' => true,
            'portal.intake.secret' => self::INTAKE_SECRET,
            'services.stripe.webhook_secret' => self::WEBHOOK_SECRET,
        ]);
    }

    private function intake(string $entryId = '101'): TestResponse
    {
        $body = json_encode([
            'entry_id' => $entryId,
            'email' => 'order.test@example.test',
            'first_name' => 'Avery',
            'last_name' => 'Stone',
            'npn' => '12345678',
            'license_expires_at' => today()->addYear()->toDateString(),
            'stripe_customer_id' => 'cus_Order',
            'stripe_subscription_id' => 'sub_Order',
        ]);
        $timestamp = now()->getTimestamp();

        return $this->call('POST', '/api/intake/individual', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, self::INTAKE_SECRET),
        ], $body);
    }

    private function webhook(string $id, string $type, array $object, int $created): TestResponse
    {
        $body = json_encode(['id' => $id, 'object' => 'event', 'type' => $type, 'created' => $created, 'data' => ['object' => $object]]);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, self::WEBHOOK_SECRET);

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.$signature,
        ], $body);
    }

    private function paidInvoice(int $periodEnd): array
    {
        return [
            'object' => 'invoice', 'customer' => 'cus_Order', 'amount_paid' => 16900, 'currency' => 'usd',
            'lines' => ['data' => [['description' => 'Alliance Membership Subscription', 'period' => ['end' => $periodEnd]]]],
        ];
    }

    private function profile(): MemberProfile
    {
        return User::where('email', 'order.test@example.test')->firstOrFail()->memberProfile;
    }

    public function test_events_arriving_before_the_application_are_applied_when_the_member_is_created(): void
    {
        Queue::fake();
        $periodEnd = now()->addYear()->startOfSecond()->getTimestamp();

        $this->webhook('evt_sub', 'customer.subscription.created', ['object' => 'subscription', 'customer' => 'cus_Order', 'status' => 'incomplete', 'items' => ['data' => [['current_period_end' => $periodEnd]]]], now()->subMinutes(2)->getTimestamp())
            ->assertOk()->assertJsonPath('outcome', 'unmatched');
        $this->webhook('evt_paid', 'invoice.paid', $this->paidInvoice($periodEnd), now()->subMinute()->getTimestamp())
            ->assertOk()->assertJsonPath('outcome', 'unmatched');

        // Kept without member data: the customer id and what the event says.
        $stored = StripeEvent::find('evt_paid');
        $this->assertSame('cus_Order', $stored->stripe_customer_id);
        $this->assertSame('active', $stored->pending_status);

        $this->intake()->assertOk()->assertJsonPath('outcome', 'created');

        $profile = $this->profile();
        $this->assertSame(PaymentStatus::Active, $profile->payment_status);
        $this->assertSame($periodEnd, $profile->paid_through_at->getTimestamp());
        $this->assertSame('replayed', $stored->fresh()->outcome);
        $this->assertSame($profile->id, $stored->fresh()->member_profile_id);
        $this->assertSame(2, StripeEvent::where('outcome', 'replayed')->count());
        $this->assertTrue(AuditLog::where('action', 'payment_status_changed')->where('resource_id', $profile->id)->exists());

        // A repeat replays nothing.
        $this->intake()->assertOk();
        $this->assertSame(2, StripeEvent::where('outcome', 'replayed')->count());
    }

    public function test_events_of_another_customer_are_left_alone(): void
    {
        Queue::fake();
        $this->webhook('evt_other', 'invoice.paid', ['customer' => 'cus_Someone', 'lines' => ['data' => [['period' => ['end' => now()->addYear()->getTimestamp()]]]]] + ['object' => 'invoice'], now()->getTimestamp())->assertOk();

        $this->intake()->assertOk();

        $this->assertNull($this->profile()->paid_through_at);
        $this->assertSame(PaymentStatus::Unknown, $this->profile()->payment_status);
        $this->assertSame('unmatched', StripeEvent::find('evt_other')->outcome);
    }

    public function test_events_arriving_after_the_application_match_by_customer_id(): void
    {
        Queue::fake();
        $periodEnd = now()->addYear()->startOfSecond()->getTimestamp();

        $this->intake()->assertOk();
        $this->assertNull($this->profile()->paid_through_at);

        $this->webhook('evt_paid', 'invoice.paid', $this->paidInvoice($periodEnd), now()->getTimestamp())
            ->assertOk()->assertJsonPath('outcome', 'updated');

        $this->assertSame(PaymentStatus::Active, $this->profile()->payment_status);
        $this->assertSame($periodEnd, $this->profile()->paid_through_at->getTimestamp());
    }

    public function test_the_job_is_queued_only_with_a_secret_key_and_a_subscription(): void
    {
        Queue::fake();

        $this->intake('101')->assertOk();
        Queue::assertNotPushed(SyncStripeSubscription::class);

        User::where('email', 'order.test@example.test')->first()->delete();
        config(['services.stripe.secret' => 'sk_test_x']);

        $this->intake('102')->assertOk();
        Queue::assertPushed(SyncStripeSubscription::class, fn ($job) => $job->memberProfileId === $this->profile()->id);

        // A repeat does not queue it again.
        Queue::fake();
        $this->intake('102')->assertOk();
        Queue::assertNotPushed(SyncStripeSubscription::class);
    }

    public function test_the_job_sets_the_paid_period_and_status_from_stripe(): void
    {
        Queue::fake();
        config(['services.stripe.secret' => 'sk_test_x']);
        $this->intake()->assertOk();
        $profile = $this->profile();
        $periodEnd = now()->addYear()->startOfSecond()->getTimestamp();

        $this->reader(['customer' => 'cus_Order', 'status' => 'active', 'period_end' => $periodEnd]);
        (new SyncStripeSubscription($profile->id))->handle(app(SubscriptionReader::class), app(StripeWebhookHandler::class), app(ServiceAccount::class));

        $profile->refresh();
        $this->assertSame(PaymentStatus::Active, $profile->payment_status);
        $this->assertSame($periodEnd, $profile->paid_through_at->getTimestamp());
        $this->assertTrue(AuditLog::where('action', 'payment_status_changed')->where('resource_id', $profile->id)->where('user_id', $this->apiService()->id)->exists());

        // An event older than the API's answer changes the status no more; the period only moves forward.
        $this->webhook('evt_old', 'invoice.payment_failed', ['object' => 'invoice', 'customer' => 'cus_Order'], now()->subHour()->getTimestamp())
            ->assertOk()->assertJsonPath('outcome', 'stale');
        $this->assertSame(PaymentStatus::Active, $profile->fresh()->payment_status);
    }

    public function test_the_job_ignores_a_subscription_of_another_customer(): void
    {
        Queue::fake();
        config(['services.stripe.secret' => 'sk_test_x']);
        $this->intake()->assertOk();
        $profile = $this->profile();

        $this->reader(['customer' => 'cus_Else', 'status' => 'active', 'period_end' => now()->addYear()->getTimestamp()]);
        (new SyncStripeSubscription($profile->id))->handle(app(SubscriptionReader::class), app(StripeWebhookHandler::class), app(ServiceAccount::class));

        $this->assertNull($profile->fresh()->paid_through_at);
        $this->assertSame(PaymentStatus::Unknown, $profile->fresh()->payment_status);
        $this->assertTrue(AuditLog::where('action', 'stripe_subscription_mismatch')->exists());
    }

    public function test_the_ping_checks_the_signature_and_changes_nothing(): void
    {
        $timestamp = now()->getTimestamp();
        $sign = fn (string $secret) => ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_TIMESTAMP' => (string) $timestamp, 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $timestamp.'.', $secret)];

        $this->call('POST', '/api/intake/ping', [], [], [], $sign(self::INTAKE_SECRET))->assertOk()->assertExactJson(['status' => 'ok']);
        $this->call('POST', '/api/intake/ping', [], [], [], $sign('wrong'))->assertUnauthorized();

        config(['portal.intake.enabled' => false]);
        $this->call('POST', '/api/intake/ping', [], [], [], $sign(self::INTAKE_SECRET))->assertNotFound();
    }

    /**
     * @param  array{customer: ?string, status: string, period_end: ?int}  $answer
     */
    private function reader(array $answer): void
    {
        $reader = Mockery::mock(SubscriptionReader::class);
        $reader->shouldReceive('read')->once()->with('sub_Order')->andReturn($answer);
        $this->app->instance(SubscriptionReader::class, $reader);
    }
}
