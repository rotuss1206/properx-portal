<?php

namespace Tests\Feature\Stripe;

use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\MemberProfile;
use App\Models\StripeEvent;
use App\Models\User;
use App\Services\ApiKeys\ApiKeyManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role as SpatieRole;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * Stripe payment status webhook (Spec 7, 3.7).
 */
class StripeWebhookTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    private User $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        config(['services.stripe.webhook_secret' => self::SECRET]);
        $this->service = $this->apiService();

        // Stripe is authenticated by the signature only: no API key is configured.
        config(['services.api_service.key' => null]);
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    private function event(string $type, array $object, ?int $created = null, ?string $id = null): array
    {
        return [
            'id' => $id ?? 'evt_'.Str::random(16),
            'object' => 'event',
            'type' => $type,
            'created' => $created ?? now()->getTimestamp(),
            'data' => ['object' => $object],
        ];
    }

    /**
     * Send a correctly signed webhook, like Stripe does.
     *
     * @param  array<string, mixed>  $event
     */
    private function send(array $event, ?string $secret = self::SECRET): TestResponse
    {
        $payload = json_encode($event);
        $timestamp = now()->getTimestamp();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return $this->call('POST', route('webhooks.stripe'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $payload);
    }

    private function memberWithCustomer(string $customerId = 'cus_123', PaymentStatus $status = PaymentStatus::Active): MemberProfile
    {
        $profile = $this->member()->memberProfile;
        $profile->forceFill([
            'stripe_customer_id' => $customerId,
            'payment_status' => $status,
            'payment_status_updated_at' => now()->subDays(30),
        ])->save();

        return $profile;
    }

    public function test_rejects_missing_or_invalid_signature(): void
    {
        $profile = $this->memberWithCustomer();
        $event = $this->event('invoice.payment_failed', ['customer' => 'cus_123']);

        $this->postJson(route('webhooks.stripe'), $event)->assertStatus(400);
        $this->send($event, secret: 'whsec_wrong')->assertStatus(400);

        $this->assertSame(PaymentStatus::Active, $profile->fresh()->payment_status);
        $this->assertSame(0, StripeEvent::count());
    }

    public function test_rejects_replayed_old_signature(): void
    {
        $this->memberWithCustomer();
        $payload = json_encode($this->event('invoice.paid', ['customer' => 'cus_123']));
        $timestamp = now()->subMinutes(10)->getTimestamp();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", self::SECRET);

        $this->call('POST', route('webhooks.stripe'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $payload)->assertStatus(400);
    }

    public function test_is_unavailable_without_a_configured_secret(): void
    {
        config(['services.stripe.webhook_secret' => null]);

        $this->send($this->event('invoice.paid', ['customer' => 'cus_123']))->assertStatus(503);
    }

    /**
     * @return array<string, array{string, array<string, mixed>, PaymentStatus, PaymentStatus}>
     */
    public static function statusChanges(): array
    {
        return [
            'invoice.payment_failed' => ['invoice.payment_failed', [], PaymentStatus::Active, PaymentStatus::PastDue],
            'invoice.paid' => ['invoice.paid', [], PaymentStatus::PastDue, PaymentStatus::Active],
            'subscription deleted' => ['customer.subscription.deleted', ['status' => 'canceled'], PaymentStatus::Active, PaymentStatus::Canceled],
            'subscription past_due' => ['customer.subscription.updated', ['status' => 'past_due'], PaymentStatus::Active, PaymentStatus::PastDue],
            'subscription unpaid' => ['customer.subscription.updated', ['status' => 'unpaid'], PaymentStatus::Active, PaymentStatus::PastDue],
            'subscription active' => ['customer.subscription.updated', ['status' => 'active'], PaymentStatus::Canceled, PaymentStatus::Active],
        ];
    }

    #[DataProvider('statusChanges')]
    public function test_events_update_the_payment_status(string $type, array $object, PaymentStatus $from, PaymentStatus $to): void
    {
        $profile = $this->memberWithCustomer(status: $from);

        $this->send($this->event($type, ['customer' => 'cus_123'] + $object))
            ->assertOk()
            ->assertJson(['outcome' => 'updated']);

        $profile->refresh();
        $this->assertSame($to, $profile->payment_status);
        $this->assertTrue($profile->payment_status_updated_at->isToday());
    }

    public function test_processing_runs_as_the_api_service_account_and_is_audited(): void
    {
        $profile = $this->memberWithCustomer();

        $this->send($this->event('invoice.payment_failed', ['customer' => 'cus_123']))->assertOk();

        foreach (['payment_status_changed', 'stripe_event_processed'] as $action) {
            $this->assertDatabaseHas('audit_logs', [
                'action' => $action,
                'user_id' => $this->service->id,
                'resource_id' => $profile->id,
            ]);
        }

        // Not mistaken for a profile edit.
        $this->assertSame(0, AuditLog::where('action', 'profile_updated')->count());
    }

    public function test_requires_an_api_service_account(): void
    {
        $this->memberWithCustomer();
        $this->service->removeRole('API Service');

        $this->send($this->event('invoice.paid', ['customer' => 'cus_123']))->assertStatus(503);
        $this->assertSame(0, StripeEvent::count());
    }

    public function test_ignored_events_are_acknowledged_even_without_an_api_service_account(): void
    {
        $this->memberWithCustomer();
        $this->service->removeRole('API Service');

        $this->send($this->event('payment_intent.succeeded', ['customer' => 'cus_123']))
            ->assertOk()
            ->assertJson(['outcome' => 'ignored']);
        $this->assertSame(0, StripeEvent::count());
    }

    public function test_has_its_own_higher_rate_limit(): void
    {
        // Above the machine API's 120 per minute, below the webhook's 600.
        foreach (range(1, 121) as $attempt) {
            $this->send($this->event('charge.succeeded', ['customer' => 'cus_123']))->assertOk();
        }

        config(['services.stripe.webhook_requests_per_minute' => 121]);

        $this->send($this->event('charge.succeeded', ['customer' => 'cus_123']))->assertTooManyRequests();
    }

    public function test_works_without_an_api_service_key(): void
    {
        $profile = $this->memberWithCustomer();

        // No key at all, or one that is not registered: neither matters to the webhook.
        foreach ([null, 'ppx_not-a-registered-key'] as $key) {
            config(['services.api_service.key' => $key]);

            $this->send($this->event('invoice.payment_failed', ['customer' => 'cus_123']))->assertOk();
        }

        $this->assertSame(PaymentStatus::PastDue, $profile->fresh()->payment_status);
        $this->assertSame(0, $this->service->apiKeys()->count());
        $this->assertSame(2, StripeEvent::count());
    }

    public function test_a_bad_signature_is_rejected_even_with_an_api_key(): void
    {
        [, $plainKey] = app(ApiKeyManager::class)->issue($this->service);
        config(['services.api_service.key' => $plainKey]);

        $this->postJson('/api/webhooks/stripe', $this->event('invoice.paid', ['customer' => 'cus_123']), [
            'Stripe-Signature' => 't='.time().',v1=forged',
            'Authorization' => 'Bearer '.$plainKey,
        ])->assertStatus(400);

        $this->assertSame(0, StripeEvent::count());
    }

    public function test_requires_the_webhook_permissions(): void
    {
        $this->memberWithCustomer();
        SpatieRole::findByName(Role::ApiService->value)->revokePermissionTo('webhooks.stripe.handle');
        Log::spy();

        $this->send($this->event('invoice.paid', ['customer' => 'cus_123']))->assertStatus(503);

        $this->assertSame(0, StripeEvent::count());
        Log::shouldHaveReceived('critical')->once();
    }

    public function test_duplicate_events_are_processed_once(): void
    {
        $profile = $this->memberWithCustomer();
        $event = $this->event('invoice.payment_failed', ['customer' => 'cus_123'], id: 'evt_duplicate');

        $this->send($event)->assertJson(['outcome' => 'updated']);

        // Status changes in between; a redelivery of the old event must not undo it.
        $profile->refresh()->forceFill(['payment_status' => PaymentStatus::Active, 'payment_status_updated_at' => now()->addMinute()])->save();

        $this->send($event)->assertOk()->assertJson(['outcome' => 'duplicate']);

        $this->assertSame(PaymentStatus::Active, $profile->fresh()->payment_status);
        $this->assertSame(1, StripeEvent::count());
        $this->assertSame(1, AuditLog::where('action', 'stripe_event_processed')->count());
    }

    public function test_out_of_order_events_are_ignored(): void
    {
        $profile = $this->memberWithCustomer();

        $this->send($this->event('invoice.paid', ['customer' => 'cus_123'], created: now()->getTimestamp()))->assertOk();
        $this->send($this->event('invoice.payment_failed', ['customer' => 'cus_123'], created: now()->subHour()->getTimestamp()))
            ->assertJson(['outcome' => 'stale']);

        $this->assertSame(PaymentStatus::Active, $profile->fresh()->payment_status);
    }

    public function test_falls_back_to_email_and_links_the_customer_id(): void
    {
        $member = $this->member();

        $this->send($this->event('invoice.payment_failed', [
            'customer' => 'cus_new',
            'customer_email' => strtoupper($member->email),
        ]))->assertJson(['outcome' => 'updated']);

        $profile = $member->memberProfile->fresh();
        $this->assertSame('cus_new', $profile->stripe_customer_id);
        $this->assertSame(PaymentStatus::PastDue, $profile->payment_status);

        // Later events match by customer id even without an email.
        $this->send($this->event('customer.subscription.deleted', ['customer' => 'cus_new']))->assertJson(['outcome' => 'updated']);
        $this->assertSame(PaymentStatus::Canceled, $profile->fresh()->payment_status);
    }

    public function test_unmatched_events_are_logged_with_a_warning(): void
    {
        Log::spy();

        $this->send($this->event('invoice.paid', ['customer' => 'cus_unknown', 'customer_email' => 'nobody@example.com']))
            ->assertOk()
            ->assertJson(['outcome' => 'unmatched']);

        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => str_contains($message, 'could not be matched')
            && ! str_contains(json_encode($context), 'nobody@example.com'))->once(); // IDs only, no email
        $this->assertDatabaseHas('stripe_events', ['outcome' => 'unmatched']);
    }

    public function test_unrelated_event_types_are_acknowledged_and_ignored(): void
    {
        $this->send($this->event('charge.succeeded', ['customer' => 'cus_123']))
            ->assertOk()
            ->assertJson(['outcome' => 'ignored']);
    }
}
