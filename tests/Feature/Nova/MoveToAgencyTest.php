<?php

namespace Tests\Feature\Nova;

use App\Enums\AgencyStatus;
use App\Enums\AgencyTier;
use App\Enums\InviteStatus;
use App\Enums\LicenseVerification;
use App\Enums\MemberType;
use App\Enums\PaymentStatus;
use App\Enums\PersonType;
use App\Enums\RateType;
use App\Enums\Role;
use App\Jobs\SendAgencyInvitation;
use App\Livewire\AgencyRegistration\Wizard;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\MemberProfile;
use App\Models\User;
use App\Notifications\AgencyPeopleAdded;
use App\Notifications\Member\MovedToAgency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * Staff move an individual member into an agency (client's answer: staff only): the agency pays as
 * for one person added, the person keeps their account, their individual subscription is left for
 * staff to cancel on properx.org.
 */
class MoveToAgencyTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected Agency $agency;

    protected User $principal;

    protected MemberProfile $member;

    /** @var list<array{0: string, 1: int}> Stripe's proration lines */
    private array $lines = [['Unused time on 2 × ProPerX Agency Membership', -15900], ['Remaining time on 3 × ProPerX Agency Membership', 23850]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSecond();

        $this->seedRoles();
        config(['services.stripe.secret' => 'sk_test_fake', 'agency.surcharge_exempt_states' => []]);
        Queue::fake([SendAgencyInvitation::class]);

        $this->agency = Agency::factory()->create([
            'legal_name' => 'Lone Star Benefits LLC',
            'tier' => AgencyTier::Agency,
            'rate_type' => RateType::Founding,
            'status' => AgencyStatus::Active,
            'paid_through_at' => now()->addMonths(6)->startOfDay(),
            'legal_address' => '400 Congress Ave, Austin, CA 90001',
            'stripe_customer_id' => 'cus_agency',
            'stripe_subscription_id' => 'sub_agency',
        ]);
        $this->principal = User::factory()->withTwoFactor()->create()->assignRole(Role::Member->value);
        MemberProfile::factory()->inAgency($this->agency, InviteStatus::AccountActive)->for($this->principal)->create();
        $this->agency->update(['principal_user_id' => $this->principal->id]);
        MemberProfile::factory()->inAgency($this->agency)->for(User::factory()->create()->assignRole(Role::Member->value))->create();
        $this->agency->refresh()->syncHeadcount();
        $this->principal->refresh();

        $this->member = MemberProfile::factory()->for(User::factory()->withTwoFactor()->create()->assignRole(Role::Member->value))->create([
            'npn' => '12345678',
            'license_state' => 'TX',
            'license_verification' => LicenseVerification::Verified,
            'payment_status' => PaymentStatus::Active,
            'stripe_customer_id' => 'cus_individual',
            'stripe_subscription_id' => 'sub_individual',
        ]);

        $prices = array_map(fn (string $key) => ['id' => 'price_'.$key, 'object' => 'price', 'active' => true, 'currency' => 'usd', 'lookup_key' => $key, 'product' => 'prod_agency', 'recurring' => ['interval' => 'year'], 'unit_amount' => 15900],
            ['agency_founding_2to9', 'agency_standard_2to9', 'agency_founding_10to49', 'agency_standard_10to49', 'agency_founding_50plus', 'agency_standard_50plus']);
        $this->stripe
            ->on('GET', '/v1/prices', ['object' => 'list', 'data' => $prices, 'has_more' => false, 'url' => '/v1/prices'])
            ->on('GET', '/v1/products', ['object' => 'list', 'data' => [['id' => 'prod_surcharge', 'object' => 'product', 'metadata' => ['properx_product' => 'card_surcharge']]], 'has_more' => false, 'url' => '/v1/products'])
            ->on('GET', '/v1/subscriptions/sub_agency', fn () => $this->subscription())
            ->on('POST', '/v1/invoices/create_preview', fn () => [
                'id' => 'upcoming_in', 'object' => 'invoice',
                'lines' => ['object' => 'list', 'has_more' => false, 'url' => '/v1/invoices/upcoming/lines', 'data' => array_map(fn (array $line) => ['object' => 'line_item', 'description' => $line[0], 'amount' => $line[1], 'parent' => ['type' => 'subscription_item_details', 'subscription_item_details' => ['proration' => true]]], $this->lines)],
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function subscription(): array
    {
        return [
            'id' => 'sub_agency', 'object' => 'subscription', 'customer' => 'cus_agency', 'status' => 'active',
            'items' => ['object' => 'list', 'data' => [['id' => 'si_agency', 'object' => 'subscription_item', 'quantity' => 2]], 'has_more' => false, 'url' => '/v1/subscription_items'],
            'default_payment_method' => ['id' => 'pm_saved', 'object' => 'payment_method', 'type' => 'card', 'card' => ['brand' => 'visa', 'display_brand' => 'visa', 'funding' => 'credit', 'last4' => '4242']],
            'latest_invoice' => ['id' => 'in_moved', 'object' => 'invoice'],
        ];
    }

    /**
     * The value Nova's modal gets for a dependent field once an agency is chosen.
     */
    private function synced(string $field, string $component): mixed
    {
        return $this->patchJson("/nova-api/member-profiles/action?action=move-to-agency&field={$field}&component={$component}&resources={$this->member->id}", [
            'agency' => (string) $this->agency->id,
        ])->assertOk()->json('value');
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function move(array $fields = [], ?MemberProfile $profile = null): TestResponse
    {
        $quote = $this->synced('quote', 'hidden.hidden-field.quote');

        return $this->postJson('/nova-api/member-profiles/action?action=move-to-agency', $fields + [
            'resources' => (string) ($profile ?? $this->member)->id,
            'agency' => (string) $this->agency->id,
            'person_type' => PersonType::LicensedProducer->value,
            'reason' => 'joined_agency',
            'quote' => $quote,
        ]);
    }

    public function test_staff_see_the_agency_s_prorated_cost_before_confirming(): void
    {
        $this->actingAs($this->staff(Role::MembershipCoordinator));

        $cost = $this->synced('cost', 'textarea.textarea-field.cost');
        $this->assertStringContainsString('$81.81 today', $cost);
        $this->assertStringContainsString('incl. credit card surcharge $2.31', $cost);
        $this->assertStringContainsString('Visa credit card ending 4242', $cost);
        $this->assertStringContainsString('People: 2 → 3', $cost);
        $this->assertSame('8181|'.now()->startOfMinute()->getTimestamp(), $this->synced('quote', 'hidden.hidden-field.quote'));

        $fields = collect($this->getJson("/nova-api/member-profiles/actions?resources={$this->member->id}")->assertOk()->json('actions'))
            ->firstWhere('uriKey', 'move-to-agency')['fields'];
        $this->assertStringContainsString('individual subscription is not canceled', collect($fields)->firstWhere('component', 'heading-field')['value']);
    }

    public function test_moving_charges_the_agency_and_keeps_the_account_without_an_invitation(): void
    {
        Notification::fake();
        $this->stripe->on('POST', '/v1/subscriptions/sub_agency', fn () => $this->subscription());
        $this->actingAs($this->staff(Role::MembershipCoordinator));

        $this->move()->assertOk()->assertJsonPath('message', fn (string $message) => str_contains($message, 'cancel any individual subscription on properx.org'));

        $update = $this->stripe->sent('POST', '/v1/subscriptions/sub_agency')[0]['params'];
        $this->assertSame([['id' => 'si_agency', 'quantity' => 3, 'price' => 'price_agency_founding_2to9']], $update['items']);
        $this->assertSame('error_if_incomplete', $update['payment_behavior']);
        $this->assertSame(now()->startOfMinute()->getTimestamp(), (int) $update['proration_date']);
        // The individual subscription is left alone.
        $this->assertSame([], $this->stripe->sent('DELETE', '/v1/subscriptions/sub_individual'));
        $this->assertSame([], $this->stripe->sent('POST', '/v1/subscriptions/sub_individual'));

        $member = $this->member->fresh();
        $this->assertSame($this->agency->id, $member->agency_id);
        $this->assertSame(MemberType::AgencyMember, $member->member_type);
        $this->assertSame(PersonType::LicensedProducer, $member->person_type);
        $this->assertSame(InviteStatus::AccountActive, $member->invite_status);
        $this->assertSame(LicenseVerification::Verified, $member->license_verification);
        $this->assertSame('sub_individual', $member->stripe_subscription_id);
        $this->assertSame(3, $this->agency->fresh()->headcount);
        $this->assertSame(3, $this->agency->fresh()->billed_headcount);

        Queue::assertNothingPushed();
        Notification::assertSentTo($member->user, MovedToAgency::class, fn (MovedToAgency $notice) => str_contains($notice->toMail($member->user)->render(), 'not canceled automatically'));
        Notification::assertSentTo($this->principal, AgencyPeopleAdded::class, function (AgencyPeopleAdded $receipt) {
            $mail = $receipt->toMail($this->principal)->render();

            return $receipt->moved && $receipt->total === 8181 && str_contains($mail, 'no invitation is sent') && ! str_contains($mail, (string) $this->member->first_name);
        });

        $audit = AuditLog::query()->where('action', 'member_moved_to_agency')->sole();
        $this->assertSame([
            'agency_id' => $this->agency->id, 'person_type' => 'licensed_producer', 'reason' => 'joined_agency',
            'amount' => 7950, 'surcharge' => 231, 'tier_from' => 'agency_2to9', 'invoice' => 'in_moved',
            'individual_subscription' => 'left_active_cancel_on_properx_org',
        ], $audit->details);
        $this->assertTrue(AuditLog::query()->where('action', 'agency_member_added')->where('resource_id', $member->id)->exists());
    }

    public function test_an_employee_needs_no_npn_and_has_no_license_check(): void
    {
        $this->stripe->on('POST', '/v1/subscriptions/sub_agency', fn () => $this->subscription());
        $this->member->forceFill(['npn' => '123'])->saveQuietly();
        $this->actingAs($this->staff(Role::ExecutiveDirector));

        // As a producer the short NPN is refused, nothing charged.
        $this->move()->assertJsonPath('danger', fn (string $message) => str_contains($message, 'NPN of 7 to 10 digits'));
        $this->assertSame([], $this->stripe->sent('POST', '/v1/subscriptions/sub_agency'));

        $this->move(['person_type' => PersonType::AgencyEmployee->value])->assertOk()->assertJsonMissingPath('danger');
        $member = $this->member->fresh();
        $this->assertSame(PersonType::AgencyEmployee, $member->person_type);
        $this->assertNull($member->license_verification);
    }

    public function test_a_refused_payment_moves_nobody(): void
    {
        Notification::fake();
        $this->stripe->on('POST', '/v1/subscriptions/sub_agency', ['error' => ['type' => 'card_error', 'code' => 'authentication_required', 'message' => 'This payment requires authentication.']], 402);
        $this->actingAs($this->staff(Role::MembershipCoordinator));

        $this->move()->assertJsonPath('danger', fn (string $message) => str_contains($message, 'Your bank wants to confirm this payment'));

        $this->assertNull($this->member->fresh()->agency_id);
        $this->assertSame(2, $this->agency->fresh()->headcount);
        $this->assertFalse(AuditLog::query()->where('action', 'member_moved_to_agency')->exists());
        Notification::assertNothingSent();
    }

    public function test_a_changed_amount_is_shown_again_instead_of_charged(): void
    {
        $this->actingAs($this->staff(Role::MembershipCoordinator));

        $this->move(['quote' => '5000|'.now()->startOfMinute()->getTimestamp()])->assertJsonPath('danger', fn (string $message) => str_contains($message, 'The amount is now $81.81'));
        $this->assertNull($this->member->fresh()->agency_id);
    }

    public function test_only_coordinators_and_the_director_move_individual_members(): void
    {
        $this->actingAs($this->staff(Role::ComplianceSpecialist));
        $actions = collect($this->getJson("/nova-api/member-profiles/actions?resources={$this->member->id}")->assertOk()->json('actions'))->pluck('uriKey');
        $this->assertNotContains('move-to-agency', $actions);
        $this->postJson('/nova-api/member-profiles/action?action=move-to-agency', ['resources' => (string) $this->member->id, 'agency' => (string) $this->agency->id, 'person_type' => 'licensed_producer', 'reason' => 'joined_agency', 'quote' => '1|1'])->assertForbidden();

        // Somebody already on an agency is not moved.
        $this->actingAs($this->staff(Role::MembershipCoordinator));
        $onAgency = $this->agency->members()->where('user_id', '!=', $this->principal->id)->firstOrFail();
        $this->move([], $onAgency)->assertJsonStructure(['danger']);
        $this->assertSame($this->agency->id, $onAgency->fresh()->agency_id);
        $this->assertNull($this->member->fresh()->agency_id);
    }

    public function test_the_wizard_still_refuses_an_existing_member_s_email(): void
    {
        config(['portal.registration.enabled' => true]);
        $people = Livewire::test(Wizard::class)->instance()->people;
        $errors = $people->rowErrors(['type' => 'licensed_producer', 'first_name' => 'Ann', 'last_name' => 'Lee', 'email' => $this->member->user->email, 'npn' => '87654321', 'license_state' => 'TX']);

        $this->assertSame('This email is already registered in the portal. Please contact ProPerX.', $errors['email'] ?? null);
    }
}
