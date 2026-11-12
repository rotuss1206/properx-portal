<?php

namespace Tests\Feature\Member;

use App\Enums\AgencyStatus;
use App\Enums\AgencyTier;
use App\Enums\RateType;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * /member/billing (Agency spec 6, G5; mockup Billing.jpg): the principal sees the agency's
 * subscription, payment method and invoices from Stripe, an individual member their own, other
 * agency people who pays; "Manage payment method" opens Stripe's Customer Portal.
 */
class BillingPageTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    private Agency $agency;

    private User $principal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        config([
            'portal.member_area.enabled' => true,
            'services.stripe.secret' => 'sk_test_fake',
            'portal.payments.payment_url' => 'https://pay.example.test/portal',
        ]);

        $this->agency = $this->agency(3, [
            'legal_name' => 'Lone Star Benefits LLC',
            'tier' => AgencyTier::AgencyPlus,
            'rate_type' => RateType::Founding,
            'status' => AgencyStatus::Active,
            'paid_through_at' => now()->addMonths(10),
        ]);
        $this->agency->forceFill(['stripe_customer_id' => 'cus_agency', 'stripe_subscription_id' => 'sub_agency'])->saveQuietly();
        $this->principal = $this->agency->principal()->first();
    }

    private function answerStripe(string $customer = 'cus_agency', ?string $subscription = 'sub_agency'): void
    {
        $renews = now()->addMonths(10)->getTimestamp();
        $sub = [
            'id' => $subscription ?? 'sub_found', 'object' => 'subscription', 'customer' => $customer, 'status' => 'active', 'currency' => 'usd',
            'cancel_at_period_end' => false,
            'default_payment_method' => ['id' => 'pm_1', 'object' => 'payment_method', 'type' => 'card', 'card' => ['brand' => 'visa', 'display_brand' => 'visa', 'last4' => '4242', 'exp_month' => 8, 'exp_year' => 2027]],
            'items' => ['object' => 'list', 'data' => [[
                'id' => 'si_1', 'object' => 'subscription_item', 'quantity' => 3, 'current_period_end' => $renews,
                'price' => ['id' => 'price_1', 'object' => 'price', 'unit_amount' => 14900, 'recurring' => ['interval' => 'year'], 'product' => ['id' => 'prod_1', 'object' => 'product', 'name' => 'ProPerX Agency Membership']],
            ]]],
        ];

        if ($subscription !== null) {
            $this->stripe->on('GET', "/v1/subscriptions/{$subscription}", $sub);
        } else {
            $this->stripe->on('GET', '/v1/subscriptions', ['object' => 'list', 'data' => [$sub]]);
        }

        $this->stripe->on('GET', '/v1/invoices', ['object' => 'list', 'data' => [
            ['id' => 'in_2', 'object' => 'invoice', 'number' => 'INV-0002', 'status' => 'open', 'total' => 44700, 'currency' => 'usd', 'created' => now()->getTimestamp(), 'invoice_pdf' => 'https://pay.stripe.com/invoice/in_2/pdf', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/in_2'],
            ['id' => 'in_1', 'object' => 'invoice', 'number' => 'INV-0001', 'status' => 'paid', 'total' => 46000, 'currency' => 'usd', 'created' => now()->subYear()->getTimestamp(), 'status_transitions' => ['finalized_at' => now()->subYear()->getTimestamp()], 'invoice_pdf' => 'https://pay.stripe.com/invoice/in_1/pdf', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/in_1'],
            ['id' => 'in_draft', 'object' => 'invoice', 'number' => null, 'status' => 'draft', 'total' => 1, 'currency' => 'usd', 'created' => now()->getTimestamp()],
        ]]);
    }

    public function test_the_principal_sees_the_agencys_plan_payment_method_and_invoices(): void
    {
        $this->answerStripe();

        $response = $this->actingAs($this->principal)->get(route('member.billing'))->assertOk();

        $response->assertSeeInOrder(['Current plan', 'Active', 'Agency Plus membership', 'Founding rate', '$149', '/ person / year', 'Next payment', 'People', '3 people', 'Total', '$447 / year']);
        $response->assertSee('Visa ending 4242')->assertSee('Expires 08 / 2027')->assertSee('Manage payment method');
        $response->assertSeeInOrder(['INV-0002', 'Due', 'Pay', 'INV-0001', '$460.00', 'Paid', 'PDF']);
        $response->assertSee('href="https://pay.stripe.com/invoice/in_1/pdf"', false);
        $response->assertDontSee('This section is coming soon');
        $this->assertCount(1, $this->stripe->sent('GET', '/v1/subscriptions/sub_agency'));
        $this->assertSame('cus_agency', $this->stripe->sent('GET', '/v1/invoices')[0]['params']['customer']);

        // The form may lead to Stripe's portal on this page only.
        $this->assertStringContainsString("form-action 'self' https://billing.stripe.com", (string) $response->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('billing.stripe.com', (string) $this->get(route('member.profile'))->headers->get('Content-Security-Policy'));
    }

    public function test_manage_payment_method_opens_the_customer_portal_for_the_agency_and_comes_back(): void
    {
        config(['services.stripe.portal_configuration' => 'bpc_test']);
        $this->stripe->on('POST', '/v1/billing_portal/sessions', ['id' => 'bps_1', 'object' => 'billing_portal.session', 'url' => 'https://billing.stripe.com/p/session/test_1']);

        $this->actingAs($this->principal)->post(route('member.billing.portal'))
            ->assertStatus(303)
            ->assertRedirect('https://billing.stripe.com/p/session/test_1');

        $params = $this->stripe->sent('POST', '/v1/billing_portal/sessions')[0]['params'];
        $this->assertSame(['customer' => 'cus_agency', 'return_url' => route('member.billing'), 'configuration' => 'bpc_test'], $params);

        $entry = AuditLog::query()->where('action', 'billing_portal_opened')->sole();
        $this->assertSame(['kind' => 'agency'], $entry->details);
        $this->assertSame($this->principal->id, $entry->user_id);
    }

    public function test_stripe_not_answering_keeps_the_page_with_the_portals_own_data(): void
    {
        $this->stripe->on('GET', '/v1/subscriptions/sub_agency', ['error' => ['type' => 'api_error', 'message' => 'card 4242 something']], 500);

        $this->actingAs($this->principal)->get(route('member.billing'))->assertOk()
            ->assertSee('We can’t load your billing details from Stripe right now.', false)
            ->assertSee('Agency Plus membership')
            ->assertSee('$149')
            ->assertSee('Invoices are not available right now')
            ->assertDontSee('card 4242 something');
    }

    public function test_other_agency_people_learn_who_pays_and_see_no_amounts(): void
    {
        $person = $this->agency->members()->where('user_id', '!=', $this->principal->id)->first()->user;

        $this->actingAs($person)->get(route('member.billing'))->assertOk()
            ->assertSee('Your membership is paid by Lone Star Benefits LLC')
            ->assertDontSee('$149')
            ->assertDontSee('Manage payment method');
        $this->post(route('member.billing.portal'))->assertNotFound();
        $this->assertSame([], $this->stripe->requests);
    }

    public function test_an_individual_member_sees_their_own_subscription_found_by_customer(): void
    {
        $member = $this->member();
        $member->memberProfile->forceFill(['stripe_customer_id' => 'cus_member', 'stripe_subscription_id' => null])->save();
        $this->answerStripe('cus_member', null);
        $this->stripe->on('POST', '/v1/billing_portal/sessions', ['id' => 'bps_2', 'object' => 'billing_portal.session', 'url' => 'https://billing.stripe.com/p/session/test_2']);

        $this->actingAs($member)->get(route('member.billing'))->assertOk()
            ->assertSee('Individual membership')
            ->assertSee('/ year')
            ->assertDontSee('/ person / year')
            ->assertSee('Visa ending 4242');
        $this->assertSame('cus_member', $this->stripe->sent('GET', '/v1/subscriptions')[0]['params']['customer']);

        $this->post(route('member.billing.portal'))->assertRedirect('https://billing.stripe.com/p/session/test_2');
        $this->assertSame('cus_member', $this->stripe->sent('POST', '/v1/billing_portal/sessions')[0]['params']['customer']);
    }

    public function test_a_subscription_of_another_customer_is_never_shown(): void
    {
        $this->answerStripe('cus_someone_else');
        $this->stripe->on('GET', '/v1/customers/cus_agency', ['id' => 'cus_agency', 'object' => 'customer', 'invoice_settings' => ['default_payment_method' => null]]);

        $this->actingAs($this->principal)->get(route('member.billing'))->assertOk()
            ->assertDontSee('Visa ending 4242')
            ->assertSee('No payment method on file')
            ->assertSee('Agency Plus membership');
    }

    public function test_an_individual_without_a_linked_customer_gets_the_payment_page(): void
    {
        $member = $this->member();

        $this->actingAs($member)->get(route('member.billing'))->assertOk()
            ->assertSee('Your payment details are not linked to the portal yet')
            ->assertSee('href="https://pay.example.test/portal"', false)
            ->assertDontSee('Manage payment method');
        $this->post(route('member.billing.portal'))->assertNotFound();
        $this->assertSame([], $this->stripe->requests);
    }
}
