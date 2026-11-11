<?php

namespace Tests\Feature\Agencies;

use App\Enums\AgencyRole;
use App\Enums\AgencyStatus;
use App\Enums\InviteStatus;
use App\Enums\LicenseVerification;
use App\Enums\PersonType;
use App\Enums\Role;
use App\Livewire\AgencyRegistration\Wizard;
use App\Models\Agency;
use App\Models\AgencyRegistrationDraft;
use App\Models\AuditLog;
use App\Models\PartnerCode;
use App\Models\User;
use App\Notifications\AgencyBankPaymentFailed;
use App\Notifications\AgencyEnrollmentCompleted;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * The agency enrollment's payment (Agency spec 6): surcharge before paying, the customer and the
 * subscription in Stripe (through Tests\Support\FakeStripe), the agency created once, whoever comes
 * first, the browser or the webhook, and bank payments that process or fail.
 */
class AgencyPaymentTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test_secret';

    private const AGENCY = [
        'legal_name' => 'Lone Star Benefits Group LLC', 'dba' => '', 'agency_type' => 'health_and_life', 'agency_npn' => '', 'fein' => '74-1234567',
        'legal_address' => ['line1' => '400 Congress Ave', 'line2' => 'Suite 210', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701'],
        'phone' => '(512) 555-0142', 'website' => '', 'billing_email' => 'billing@lonestarbenefits.example', 'referral_source' => 'partner',
    ];

    private const PRINCIPAL = [
        'first_name' => 'Dana', 'last_name' => 'Whitfield', 'title' => 'owner', 'email' => 'dana@lonestarbenefits.example',
        'mobile_phone' => '512-555-0199', 'npn' => '18273645', 'license_state' => 'TX', 'authority_confirmed' => true,
    ];

    private const PEOPLE = [
        ['type' => 'licensed_producer', 'first_name' => 'Marcus', 'last_name' => 'Reyes', 'email' => 'marcus@lonestarbenefits.example', 'npn' => '20304050', 'license_state' => 'TX'],
        ['type' => 'agency_employee', 'first_name' => 'Priya', 'last_name' => 'Nair', 'email' => 'priya@lonestarbenefits.example', 'npn' => '', 'license_state' => ''],
    ];

    /** Dues of 3 people at the founding Agency rate, and 2.9% of them. */
    private const DUES = 47700;

    private const SURCHARGE = 1383;

    private int $periodEnd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->apiService();
        config([
            'portal.registration.enabled' => true,
            'services.stripe.key' => 'pk_test_fake',
            'services.stripe.secret' => 'sk_test_fake',
            'services.stripe.webhook_secret' => self::WEBHOOK_SECRET,
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'America/New_York'));
        $this->periodEnd = now()->addYear()->getTimestamp();
        $this->stripeAccount();
    }

    // Stripe's side

    private function stripeAccount(): void
    {
        $prices = array_map(fn (string $key) => ['id' => 'price_'.$key, 'object' => 'price', 'active' => true, 'currency' => 'usd', 'lookup_key' => $key, 'product' => 'prod_agency', 'recurring' => ['interval' => 'year'], 'unit_amount' => 15900],
            ['agency_founding_2to9', 'agency_standard_2to9', 'agency_founding_10to49', 'agency_standard_10to49', 'agency_founding_50plus', 'agency_standard_50plus']);

        $this->stripe
            ->on('GET', '/v1/prices', ['object' => 'list', 'data' => $prices, 'has_more' => false, 'url' => '/v1/prices'])
            ->on('GET', '/v1/products', ['object' => 'list', 'data' => [['id' => 'prod_surcharge', 'object' => 'product', 'metadata' => ['properx_product' => 'card_surcharge']]], 'has_more' => false, 'url' => '/v1/products'])
            ->on('GET', '/v1/confirmation_tokens/ctoken_credit', $this->token('card', ['brand' => 'visa', 'display_brand' => 'visa', 'funding' => 'credit', 'last4' => '4242']))
            ->on('GET', '/v1/confirmation_tokens/ctoken_debit', $this->token('card', ['brand' => 'visa', 'display_brand' => 'visa', 'funding' => 'debit', 'last4' => '5556']))
            ->on('GET', '/v1/confirmation_tokens/ctoken_bank', $this->token('us_bank_account', ['bank_name' => 'STRIPE TEST BANK', 'last4' => '6789']))
            ->on('POST', '/v1/customers', fn () => ['id' => 'cus_agency', 'object' => 'customer'])
            ->on('POST', '/v1/subscriptions', fn () => $this->subscription('incomplete'))
            ->on('GET', '/v1/subscriptions/sub_agency', fn () => $this->subscription('incomplete'))
            ->on('DELETE', '/v1/subscriptions/sub_agency', fn () => $this->subscription('canceled'));
    }

    /**
     * @param  array<string, string>  $details
     * @return array<string, mixed>
     */
    private function token(string $type, array $details): array
    {
        return ['id' => 'ctoken_x', 'object' => 'confirmation_token', 'expires_at' => now()->addHour()->getTimestamp(), 'payment_method_preview' => ['type' => $type, $type => $details]];
    }

    /**
     * @return array<string, mixed>
     */
    private function subscription(string $status): array
    {
        return [
            'id' => 'sub_agency', 'object' => 'subscription', 'status' => $status, 'customer' => 'cus_agency',
            'items' => ['object' => 'list', 'data' => [['id' => 'si_1', 'object' => 'subscription_item', 'current_period_end' => $this->periodEnd]]],
            'latest_invoice' => ['id' => 'in_1', 'object' => 'invoice', 'confirmation_secret' => ['client_secret' => 'pi_agency_secret_abc', 'type' => 'payment_intent']],
        ];
    }

    private function intent(string $status, ?string $customer = 'cus_agency', array $error = []): void
    {
        $this->stripe->on('GET', '/v1/payment_intents/pi_agency', ['id' => 'pi_agency', 'object' => 'payment_intent', 'status' => $status, 'customer' => $customer] + ($error === [] ? [] : ['last_payment_error' => $error]));
    }

    // The visitor's side

    private function reviewScreen(array $query = []): Testable
    {
        $wizard = Livewire::withQueryParams($query)->test(Wizard::class)
            ->set('membership.type', 'agency')->call('next')
            ->set('tier.tier', 'agency_2to9')->call('next');

        foreach (self::AGENCY as $key => $value) {
            $wizard->set("agency.{$key}", $value);
        }

        $wizard->call('next');

        foreach (self::PRINCIPAL as $key => $value) {
            $wizard->set("principal.{$key}", $value);
        }

        $wizard->call('next')->set('people.rows', self::PEOPLE)->call('next');

        foreach (['terms', 'dues_separate', 'annual_no_refund', 'roster_confirmed'] as $key) {
            $wizard->set("review.{$key}", true);
        }

        return $wizard->assertHasNoErrors()->assertSet('step', 6);
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function webhook(string $type, array $object): TestResponse
    {
        $payload = json_encode(['id' => 'evt_'.Str::random(12), 'object' => 'event', 'type' => $type, 'created' => now()->getTimestamp(), 'data' => ['object' => $object]]);
        // Stripe checks the signature against the real clock, not the travelled one.
        $timestamp = time();

        return $this->call('POST', route('webhooks.stripe'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", self::WEBHOOK_SECRET),
        ], $payload);
    }

    /**
     * The first invoice as Stripe sends it in the newer API versions.
     *
     * @return array<string, mixed>
     */
    private function invoice(int $draftId, string $customer = 'cus_agency', ?string $email = null): array
    {
        return [
            'id' => 'in_1', 'object' => 'invoice', 'customer' => $customer, 'customer_email' => $email, 'amount_paid' => self::DUES, 'currency' => 'usd',
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_agency', 'metadata' => ['properx_kind' => 'agency_enrollment', 'properx_draft_id' => (string) $draftId]]],
            'lines' => ['object' => 'list', 'data' => [['id' => 'il_1', 'object' => 'line_item', 'period' => ['start' => now()->getTimestamp(), 'end' => $this->periodEnd]]]],
        ];
    }

    // Surcharge before paying

    public function test_a_credit_card_shows_the_surcharge_line_and_the_new_total_before_paying(): void
    {
        $this->reviewScreen()
            ->call('preparePayment', 'ctoken_credit')
            ->assertSet('paymentMessage', '')
            ->assertHasNoErrors()
            ->assertSet('checkout.funding', 'credit')
            ->assertSet('checkout.subtotal', self::DUES)
            ->assertSet('checkout.surcharge', self::SURCHARGE)
            ->assertSet('checkout.total', self::DUES + self::SURCHARGE)
            ->assertSeeInOrder(['Paying with', '4242', 'Membership dues', '$477', 'Credit card surcharge 2.9%', '$13.83', 'Total today', '$490.83'])
            ->assertSet('checkout.label', 'Visa credit card ···· 4242')
            ->assertSee('Pay $490.83 and enroll my agency')
            ->assertDispatched('join-focus', id: 'payment-total');

        $this->assertSame([], $this->stripe->sent('POST', '/v1/subscriptions'));
    }

    public function test_debit_cards_and_bank_accounts_pay_no_surcharge(): void
    {
        $wizard = $this->reviewScreen()->call('preparePayment', 'ctoken_debit')
            ->assertSet('checkout.surcharge', 0)
            ->assertSet('checkout.total', self::DUES)
            ->assertSee('None for this payment method')
            ->assertDontSee('Credit card surcharge 2.9%')
            ->assertSee('Pay $477 and enroll my agency');

        $wizard->call('clearPaymentPreview')->assertSet('checkout', [])
            ->call('preparePayment', 'ctoken_bank')
            ->assertSet('checkout.type', 'us_bank_account')
            ->assertSet('checkout.label', 'STRIPE TEST BANK ···· 6789')
            ->assertSet('checkout.total', self::DUES);
    }

    // Customer and subscription

    public function test_paying_creates_the_customer_and_the_subscription_with_the_surcharge_as_a_first_invoice_line(): void
    {
        $started = $this->reviewScreen()->call('preparePayment', 'ctoken_credit')->call('startPayment')->effects['returns'][0];

        $this->assertSame(['clientSecret' => 'pi_agency_secret_abc', 'confirmationToken' => 'ctoken_credit', 'returnUrl' => route('join.agency')], $started);

        $draft = AgencyRegistrationDraft::query()->sole();
        $customer = $this->stripe->sent('POST', '/v1/customers')[0];
        $this->assertSame(['name' => 'Lone Star Benefits Group LLC', 'email' => 'billing@lonestarbenefits.example', 'metadata' => ['properx_kind' => 'agency_enrollment', 'properx_draft_id' => (string) $draft->id]], $customer['params']);
        $this->assertStringStartsWith("properx-agency-customer-{$draft->id}-", $customer['idempotency_key']);

        $subscription = $this->stripe->sent('POST', '/v1/subscriptions')[0];
        $this->assertSame('cus_agency', $subscription['params']['customer']);
        $this->assertSame([['price' => 'price_agency_founding_2to9', 'quantity' => 3]], $subscription['params']['items']);
        $this->assertSame('default_incomplete', $subscription['params']['payment_behavior']);
        $this->assertSame(['save_default_payment_method' => 'on_subscription', 'payment_method_types' => ['card', 'us_bank_account']], $subscription['params']['payment_settings']);
        $this->assertSame([['price_data' => ['currency' => 'usd', 'product' => 'prod_surcharge', 'unit_amount' => self::SURCHARGE], 'quantity' => 1]], $subscription['params']['add_invoice_items']);
        $this->assertSame(['properx_kind' => 'agency_enrollment', 'properx_draft_id' => (string) $draft->id, 'properx_tier' => 'agency_2to9', 'properx_rate_type' => 'founding'], $subscription['params']['metadata']);
        $this->assertStringStartsWith("properx-agency-subscription-{$draft->id}-", $subscription['idempotency_key']);

        // Nothing about the people goes to Stripe.
        $this->assertStringNotContainsString('marcus', json_encode($this->stripe->requests));

        $payment = $draft->data['payment'];
        $this->assertSame(['started', 'cus_agency', 'sub_agency', 'pi_agency'], [$payment['status'], $payment['customer'], $payment['subscription'], $payment['payment_intent']]);
        $this->assertSame(self::SURCHARGE, $payment['snapshot']['quote']['surcharge']);
        $this->assertSame(0, Agency::query()->count());
    }

    public function test_a_debit_card_gets_no_surcharge_line(): void
    {
        $this->reviewScreen()->call('preparePayment', 'ctoken_debit')->call('startPayment');

        $this->assertArrayNotHasKey('add_invoice_items', $this->stripe->sent('POST', '/v1/subscriptions')[0]['params']);
    }

    public function test_a_partner_rate_is_charged_as_that_amount_of_the_agency_product(): void
    {
        PartnerCode::factory()->create(['code' => 'MGA-7']);

        $this->reviewScreen(['partner' => 'MGA-7'])->call('preparePayment', 'ctoken_debit')->call('startPayment');

        $this->assertSame([['price_data' => ['currency' => 'usd', 'product' => 'prod_agency', 'unit_amount' => 14900, 'recurring' => ['interval' => 'year']], 'quantity' => 3]], $this->stripe->sent('POST', '/v1/subscriptions')[0]['params']['items']);
    }

    public function test_paying_twice_creates_nothing_new(): void
    {
        $wizard = $this->reviewScreen()->call('preparePayment', 'ctoken_credit')->call('startPayment');
        $again = $wizard->call('startPayment')->effects['returns'][0];

        $this->assertSame('pi_agency_secret_abc', $again['clientSecret']);
        $this->assertCount(1, $this->stripe->sent('POST', '/v1/customers'));
        $this->assertCount(1, $this->stripe->sent('POST', '/v1/subscriptions'));
    }

    public function test_changed_contents_cancel_the_unpaid_subscription_and_start_another(): void
    {
        $wizard = $this->reviewScreen()->call('preparePayment', 'ctoken_credit')->call('startPayment');

        // Another card, without a surcharge: other contents.
        $wizard->call('preparePayment', 'ctoken_debit')->call('startPayment');

        $this->assertCount(1, $this->stripe->sent('POST', '/v1/customers'));
        $this->assertCount(1, $this->stripe->sent('DELETE', '/v1/subscriptions/sub_agency'));
        $subscriptions = $this->stripe->sent('POST', '/v1/subscriptions');
        $this->assertCount(2, $subscriptions);
        $this->assertNotSame($subscriptions[0]['idempotency_key'], $subscriptions[1]['idempotency_key']);
    }

    public function test_the_server_recomputes_the_total_and_stops_when_it_changed(): void
    {
        $wizard = $this->reviewScreen()->call('preparePayment', 'ctoken_credit');

        // The founding cutoff passed between "Review payment" and "Pay".
        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:00:01', 'America/New_York'));

        $this->assertNull($wizard->call('startPayment')->effects['returns'][0]);
        $wizard->assertSet('checkout.subtotal', 53700)->assertSee('The total is now $552.57.');
        $this->assertSame([], $this->stripe->sent('POST', '/v1/subscriptions'));
    }

    // After paying

    public function test_a_card_payment_that_succeeded_enrolls_the_agency(): void
    {
        $this->intent('succeeded');

        $wizard = $this->reviewScreen()->call('preparePayment', 'ctoken_credit')->call('startPayment')->call('finishPayment', 'pi_agency');
        $draftId = AgencyRegistrationDraft::withoutGlobalScopes()->count();

        $agency = Agency::query()->sole();
        $this->assertSame(['Lone Star Benefits Group LLC', 'cus_agency', 'sub_agency', AgencyStatus::Active], [$agency->legal_name, $agency->stripe_customer_id, $agency->stripe_subscription_id, $agency->status]);
        $this->assertSame('400 Congress Ave, Suite 210, Austin, TX 78701', $agency->legal_address);
        $this->assertSame($this->periodEnd, $agency->paid_through_at->getTimestamp());
        $this->assertSame(3, $agency->headcount);

        $principal = User::query()->where('email', 'dana@lonestarbenefits.example')->sole();
        $this->assertSame($principal->id, $agency->principal_user_id);
        $this->assertTrue($principal->hasRole(Role::Member->value) && $principal->hasRole(Role::AgencyPrincipal->value));

        $people = $agency->members()->with('user')->orderBy('id')->get();
        $this->assertSame(['dana@lonestarbenefits.example', 'marcus@lonestarbenefits.example', 'priya@lonestarbenefits.example'], $people->pluck('user.email')->all());
        $this->assertSame([AgencyRole::Principal, AgencyRole::Member, AgencyRole::Member], $people->pluck('agency_role')->all());
        $this->assertSame([PersonType::LicensedProducer, PersonType::LicensedProducer, PersonType::AgencyEmployee], $people->pluck('person_type')->all());
        $this->assertSame([InviteStatus::Pending, InviteStatus::Pending, InviteStatus::Pending], $people->pluck('invite_status')->all());
        $this->assertSame([LicenseVerification::PendingReview, LicenseVerification::PendingReview, null], $people->pluck('license_verification')->all());
        $this->assertNull($people[2]->npn);

        $this->assertSame(0, $draftId);
        $audit = AuditLog::query()->where('action', 'agency_enrolled')->sole();
        $this->assertSame(['agency_2to9', 'founding', 3, self::DUES, self::SURCHARGE, 'card'], [$audit->details['tier'], $audit->details['rate_type'], $audit->details['headcount'], $audit->details['amount'], $audit->details['surcharge'], $audit->details['payment_method']]);
        $this->assertCount(4, $audit->details['consents_given_at']);
        $this->assertStringNotContainsString('Whitfield', json_encode($audit->details));

        $wizard->assertSet('enrolledAgencyId', $agency->id)
            ->assertSee('Your agency is enrolled')
            ->assertSeeInOrder(['Dana Whitfield', 'Principal', 'Invitation will be sent', 'Marcus Reyes', 'Invitation will be sent', 'Priya Nair', 'Agency employee', 'Invitation will be sent'])
            ->assertSeeInOrder(['Receipt', '$477', 'Credit card surcharge', '$13.83', 'Total paid', '$490.83']);

        // Reloading the page shows the confirmation again, and nothing is created twice.
        Livewire::test(Wizard::class)->assertSee('Your agency is enrolled')->call('finishPayment', 'pi_agency');
        $this->assertSame(1, Agency::query()->count());
    }

    public function test_a_declined_card_or_canceled_3d_secure_leaves_the_person_on_screen_6(): void
    {
        $wizard = $this->reviewScreen()->call('preparePayment', 'ctoken_credit')->call('startPayment')
            ->call('paymentFailed', 'card_declined', 'generic_decline')
            ->assertSet('checkout', [])
            ->assertSet('step', 6)
            ->assertSee('Your card was declined, so nothing was charged.')
            ->assertDispatched('join-focus', id: 'payment-message');

        $wizard->call('paymentFailed', 'payment_intent_authentication_failure')
            ->assertSee("Your card's verification was not completed, so nothing was charged.");

        // A forged code shows the general sentence, never its own text.
        $wizard->call('paymentFailed', '<script>', '')
            ->assertSee('The payment did not go through, so nothing was charged.')
            ->assertDontSee('<script>', false);

        $this->assertSame(0, Agency::query()->count());
    }

    public function test_3d_secure_left_unfinished_is_not_a_payment(): void
    {
        $this->intent('requires_action');

        $this->reviewScreen()->call('preparePayment', 'ctoken_credit')->call('startPayment')->call('finishPayment', 'pi_agency')
            ->assertSee("Your card's verification was not completed")
            ->assertSet('enrolledAgencyId', null);

        $this->assertSame(0, Agency::query()->count());
    }

    public function test_a_payment_intent_of_another_customer_is_refused(): void
    {
        $this->intent('succeeded', 'cus_someone_else');

        $this->reviewScreen()->call('preparePayment', 'ctoken_credit')->call('startPayment')->call('finishPayment', 'pi_agency')
            ->assertSee('The payment could not be started.');

        $this->assertSame(0, Agency::query()->count());
    }

    // Bank payments

    public function test_a_bank_payment_processes_then_the_webhook_enrolls_the_agency(): void
    {
        Notification::fake();
        $this->intent('processing');

        $wizard = $this->reviewScreen()->call('preparePayment', 'ctoken_bank')->call('startPayment')->call('finishPayment', 'pi_agency')
            ->assertSee('Your bank payment is processing')
            ->assertSee('email dana@lonestarbenefits.example as soon as');
        $draft = AgencyRegistrationDraft::query()->sole();
        $this->assertSame('processing', $draft->data['payment']['status']);
        $this->assertSame(0, Agency::query()->count());

        // Locked: no new payment while it processes.
        $this->assertNull($wizard->call('startPayment')->effects['returns'][0]);
        Livewire::test(Wizard::class)->assertSee('Your bank payment is processing');

        $this->webhook('invoice.paid', $this->invoice($draft->id))->assertOk()->assertJson(['outcome' => 'agency_enrolled']);

        $this->assertSame(1, Agency::query()->count());
        $this->assertNull(AgencyRegistrationDraft::query()->find($draft->id));
        Notification::assertSentOnDemand(AgencyEnrollmentCompleted::class, fn ($notification, $channels, AnonymousNotifiable $to) => $to->routes['mail'] === 'dana@lonestarbenefits.example');

        // The same browser later sees the confirmation.
        Livewire::test(Wizard::class)->assertSee('Your agency is enrolled');
    }

    public function test_a_failed_bank_payment_opens_the_enrollment_again_with_a_link_by_email(): void
    {
        Notification::fake();
        $this->intent('processing');

        $this->reviewScreen()->call('preparePayment', 'ctoken_bank')->call('startPayment')->call('finishPayment', 'pi_agency');
        $draft = AgencyRegistrationDraft::query()->sole();

        $this->webhook('invoice.payment_failed', $this->invoice($draft->id))->assertOk()->assertJson(['outcome' => 'agency_payment_failed']);

        $draft->refresh();
        $this->assertSame('failed', $draft->data['payment']['status']);
        $this->assertNull($draft->data['payment']['snapshot']);
        $this->assertSame(0, Agency::query()->count());

        $url = null;
        Notification::assertSentOnDemand(AgencyBankPaymentFailed::class, function (AgencyBankPaymentFailed $notification, $channels, AnonymousNotifiable $to) use (&$url) {
            $url = $notification->url;

            return $to->routes['mail'] === 'dana@lonestarbenefits.example';
        });

        // The link (or this browser) opens screen 6 with the reason, ready to pay again.
        $this->flushSession();
        $this->get($url)->assertRedirect(route('join.agency'));
        Livewire::test(Wizard::class)
            ->assertSet('step', 6)
            ->assertSee("Your bank didn't complete the payment, so nothing was charged.")
            ->assertSeeHtml('data-join-pay-review');
    }

    public function test_a_card_failure_reported_by_webhook_leaves_the_page_in_charge(): void
    {
        $this->reviewScreen()->call('preparePayment', 'ctoken_credit')->call('startPayment');
        $draft = AgencyRegistrationDraft::query()->sole();

        $this->webhook('invoice.payment_failed', $this->invoice($draft->id))->assertJson(['outcome' => 'agency_ignored']);
        $this->assertSame('started', $draft->fresh()->data['payment']['status']);
    }

    // Webhook before the browser, and no mix-up with individual members

    public function test_the_webhook_can_come_first_and_the_browser_then_shows_the_same_agency(): void
    {
        $this->intent('succeeded');
        $wizard = $this->reviewScreen()->call('preparePayment', 'ctoken_credit')->call('startPayment');
        $draft = AgencyRegistrationDraft::query()->sole();

        $this->webhook('invoice.paid', $this->invoice($draft->id))->assertJson(['outcome' => 'agency_enrolled']);
        $this->webhook('invoice.paid', $this->invoice($draft->id))->assertJson(['outcome' => 'agency_paid']);

        $wizard->call('finishPayment', 'pi_agency')->assertSee('Your agency is enrolled');
        $this->assertSame(1, Agency::query()->count());
        $this->assertSame(3, User::query()->where('email', 'like', '%@lonestarbenefits.example')->count());
    }

    public function test_an_agency_event_never_changes_an_individual_members_profile(): void
    {
        $member = $this->member();
        $before = $member->memberProfile->only(['payment_status', 'paid_through_at', 'stripe_customer_id']);

        $this->intent('succeeded');
        $this->reviewScreen()->call('preparePayment', 'ctoken_credit')->call('startPayment');
        $draft = AgencyRegistrationDraft::query()->sole();

        // Even with that member's email on the invoice (the individual fallback matches by email).
        $this->webhook('invoice.paid', $this->invoice($draft->id, email: $member->email))->assertJson(['outcome' => 'agency_enrolled']);

        $this->assertSame($before, $member->memberProfile->fresh()->only(['payment_status', 'paid_through_at', 'stripe_customer_id']));

        // Later events of the agency's customer are the agency's, also without the metadata.
        $renewal = $this->invoice($draft->id, email: $member->email);
        unset($renewal['parent']);
        $this->webhook('invoice.paid', $renewal)->assertJson(['outcome' => 'agency_paid']);
        $this->assertSame($before, $member->memberProfile->fresh()->only(['payment_status', 'paid_through_at', 'stripe_customer_id']));
    }

    // CSP

    public function test_only_the_enrollment_page_lets_stripe_in(): void
    {
        $csp = $this->get('/join/agency')->assertOk()->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("script-src 'self' 'nonce-", $csp);
        $this->assertStringContainsString('https://js.stripe.com https://*.js.stripe.com', $csp);
        $this->assertStringContainsString("frame-src 'self' https://js.stripe.com https://*.js.stripe.com https://hooks.stripe.com https://m.stripe.network", $csp);
        $this->assertStringContainsString("connect-src 'self' https://api.stripe.com https://m.stripe.network", $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
        $this->assertStringNotContainsString("'unsafe-inline' https", $csp);
        $this->get('/join/agency')->assertSee('https://js.stripe.com/v3/', false);

        $other = $this->get('/verify-certificate')->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('stripe', $other);
    }
}
