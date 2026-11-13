<?php

namespace Tests\Feature\Member;

use App\Enums\AgencyStatus;
use App\Enums\AgencyTier;
use App\Enums\InviteStatus;
use App\Enums\MemberStatus;
use App\Enums\PaymentStatus;
use App\Enums\RateType;
use App\Enums\Role;
use App\Jobs\SendAgencyInvitation;
use App\Livewire\Member\AddPeople;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\MemberProfile;
use App\Models\User;
use App\Notifications\AgencyPeopleAdded;
use App\Notifications\Member\AgencyEndingAtRenewal;
use App\Notifications\Member\AgencyRemovalScheduled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * Adding people from /member/agency with a prorated payment (Agency spec 8.1, G3) and removing
 * people at the next renewal (G4), with Nova's view of both (I2).
 */
class AgencyPeopleChangesTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    private const PRODUCER = ['type' => 'licensed_producer', 'first_name' => 'Elena', 'last_name' => 'Brooks', 'email' => 'elena@lonestar.example', 'npn' => '30405060', 'license_state' => 'TX'];

    private const EMPLOYEE = ['type' => 'agency_employee', 'first_name' => 'Omar', 'last_name' => 'Haddad', 'email' => 'omar@lonestar.example', 'npn' => '', 'license_state' => ''];

    private Agency $agency;

    private User $principal;

    /** @var list<MemberProfile> */
    private array $people = [];

    /** What the fake Stripe says about the saved payment method. */
    private string $funding = 'credit';

    /** Stripe's proration lines for the preview: [description, amount]. */
    private array $lines = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSecond();

        $this->seedRoles();
        config([
            'portal.member_area.enabled' => true,
            'services.stripe.secret' => 'sk_test_fake',
            'agency.surcharge_exempt_states' => [],
        ]);
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
        $this->principal = $this->person(InviteStatus::AccountActive)->user;
        $this->agency->update(['principal_user_id' => $this->principal->id]);
        $this->people[] = $this->person();
        $this->people[] = $this->person();
        $this->agency->refresh()->syncHeadcount();
        $this->principal->refresh();

        $this->lines = [
            ['Unused time on 3 × ProPerX Agency Membership', -47700],
            ['Remaining time on 4 × ProPerX Agency Membership', 55650],
        ];
        $this->stripeAccount();
    }

    private function person(InviteStatus $invite = InviteStatus::Invited): MemberProfile
    {
        $user = User::factory()->withTwoFactor()->create()->assignRole(Role::Member->value);

        return MemberProfile::factory()->inAgency($this->agency, $invite)->for($user)->create()->load('user');
    }

    private function stripeAccount(): void
    {
        $prices = array_map(fn (string $key) => ['id' => 'price_'.$key, 'object' => 'price', 'active' => true, 'currency' => 'usd', 'lookup_key' => $key, 'product' => 'prod_agency', 'recurring' => ['interval' => 'year'], 'unit_amount' => 15900],
            ['agency_founding_2to9', 'agency_standard_2to9', 'agency_founding_10to49', 'agency_standard_10to49', 'agency_founding_50plus', 'agency_standard_50plus']);

        $this->stripe
            ->on('GET', '/v1/prices', ['object' => 'list', 'data' => $prices, 'has_more' => false, 'url' => '/v1/prices'])
            ->on('GET', '/v1/products', ['object' => 'list', 'data' => [['id' => 'prod_surcharge', 'object' => 'product', 'metadata' => ['properx_product' => 'card_surcharge']]], 'has_more' => false, 'url' => '/v1/products'])
            ->on('GET', '/v1/subscriptions/sub_agency', fn () => $this->subscription())
            ->on('POST', '/v1/invoices/create_preview', fn () => [
                'id' => 'upcoming_in', 'object' => 'invoice',
                'lines' => ['object' => 'list', 'has_more' => false, 'url' => '/v1/invoices/upcoming/lines', 'data' => array_merge(
                    array_map(fn (array $line) => ['object' => 'line_item', 'description' => $line[0], 'amount' => $line[1], 'parent' => ['type' => 'subscription_item_details', 'subscription_item_details' => ['proration' => true]]], $this->lines),
                    // The next year's line is not part of today's charge.
                    [['object' => 'line_item', 'description' => '4 × ProPerX Agency Membership (next year)', 'amount' => 63600, 'parent' => ['type' => 'subscription_item_details', 'subscription_item_details' => ['proration' => false]]]],
                )],
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function subscription(): array
    {
        return [
            'id' => 'sub_agency', 'object' => 'subscription', 'customer' => 'cus_agency', 'status' => 'active',
            'items' => ['object' => 'list', 'data' => [['id' => 'si_agency', 'object' => 'subscription_item', 'quantity' => 3]], 'has_more' => false, 'url' => '/v1/subscription_items'],
            'default_payment_method' => ['id' => 'pm_saved', 'object' => 'payment_method', 'type' => 'card', 'card' => ['brand' => 'visa', 'display_brand' => 'visa', 'funding' => $this->funding, 'last4' => '4242']],
            'latest_invoice' => ['id' => 'in_added', 'object' => 'invoice'],
        ];
    }

    private function updatesSucceed(): void
    {
        $this->stripe->on('POST', '/v1/subscriptions/sub_agency', fn () => $this->subscription());
    }

    /**
     * @param  list<array<string, string>>  $people
     */
    private function review(array $people = [self::PRODUCER]): Testable
    {
        $component = Livewire::actingAs($this->principal)->test(AddPeople::class);

        foreach ($people as $person) {
            foreach ($person as $key => $value) {
                $component->set("people.entry.{$key}", $value);
            }

            $component->call('savePerson')->assertHasNoErrors();
        }

        return $component->call('reviewCost');
    }

    // Adding: the amount

    public function test_the_principal_opens_add_people_and_nobody_else_does(): void
    {
        $this->actingAs($this->principal)->get(route('member.agency'))->assertOk()->assertSee(route('member.agency.add'), false)->assertDontSee('coming soon');
        $this->get(route('member.agency.add'))->assertOk()->assertSee('Add people');

        // The CSV template works with public enrollment closed.
        config(['portal.registration.enabled' => false]);
        $this->get(route('member.agency.people-template'))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $this->actingAs($this->people[0]->user)->get(route('member.agency.add'))->assertNotFound();
        $this->get(route('member.agency.people-template'))->assertNotFound();
    }

    public function test_the_review_shows_stripe_s_prorated_amount_and_the_credit_card_surcharge(): void
    {
        $component = $this->review();

        $component->assertSet('stage', 'review')
            ->assertSet('review.after', 4)
            ->assertSet('review.tier_changes', false)
            ->assertSet('review.subtotal', 7950)
            ->assertSet('review.surcharge', 231)
            ->assertSet('review.total', 8181)
            ->assertSee('$81.81')
            ->assertSee('Visa credit card ending 4242')
            ->assertSee('Credit card surcharge');

        $preview = $this->stripe->sent('POST', '/v1/invoices/create_preview')[0]['params'];
        $this->assertSame('sub_agency', $preview['subscription']);
        $this->assertSame([['id' => 'si_agency', 'quantity' => 4, 'price' => 'price_agency_founding_2to9']], $preview['subscription_details']['items']);
        $this->assertSame('always_invoice', $preview['subscription_details']['proration_behavior']);
        $this->assertSame(now()->getTimestamp(), (int) $preview['subscription_details']['proration_date']);
    }

    public function test_no_surcharge_on_a_debit_card_or_from_an_exempt_state(): void
    {
        $this->funding = 'debit';
        $this->review()->assertSet('review.surcharge', 0)->assertSet('review.total', 7950)->assertDontSee('Credit card surcharge');

        $this->funding = 'credit';
        config(['agency.surcharge_exempt_states' => ['CA']]);
        $this->review()->assertSet('review.surcharge', 0)->assertSet('review.total', 7950);
    }

    public function test_crossing_into_the_next_tier_moves_everyone_to_its_price(): void
    {
        // 3 now; 7 more makes 10: Agency Plus for all of them, prorated.
        $people = [];

        foreach (range(1, 7) as $n) {
            $people[] = ['type' => 'agency_employee', 'first_name' => 'Person', 'last_name' => "No{$n}", 'email' => "person{$n}@lonestar.example", 'npn' => '', 'license_state' => ''];
        }

        $this->review($people)
            ->assertSet('review.after', 10)
            ->assertSet('review.tier_changes', true)
            ->assertSet('review.tier_after', AgencyTier::AgencyPlus->label())
            ->assertSet('review.rate', 14900)
            ->assertSee(AgencyTier::AgencyPlus->label());

        $items = $this->stripe->sent('POST', '/v1/invoices/create_preview')[0]['params']['subscription_details']['items'];
        $this->assertSame([['id' => 'si_agency', 'quantity' => 10, 'price' => 'price_agency_founding_10to49']], $items);
    }

    public function test_the_list_follows_the_enrollment_rules(): void
    {
        $taken = $this->people[0];
        $component = Livewire::actingAs($this->principal)->test(AddPeople::class);

        foreach (['type' => 'licensed_producer', 'first_name' => 'Al', 'last_name' => 'Short', 'email' => 'al@lonestar.example', 'npn' => '123', 'license_state' => 'TX'] as $key => $value) {
            $component->set("people.entry.{$key}", $value);
        }

        $component->call('savePerson')->assertHasErrors(['people.entry.npn']);

        $component->set('people.entry.npn', (string) $taken->npn)->call('savePerson')->assertHasErrors(['people.entry.npn']);
        $component->set('people.entry.npn', '9876543')->set('people.entry.email', $taken->user->email)->call('savePerson')->assertHasErrors(['people.entry.email']);

        // An employee needs neither NPN nor state.
        $component->call('clearPerson');

        foreach (self::EMPLOYEE as $key => $value) {
            $component->set("people.entry.{$key}", $value);
        }

        $component->call('savePerson')->assertHasNoErrors()->assertCount('people.rows', 1);
        $this->assertSame([], $this->stripe->sent('POST', '/v1/invoices/create_preview'));
    }

    public function test_a_csv_adds_rows_with_the_wizard_s_rules(): void
    {
        $csv = "type,first_name,last_name,email,npn,state\n"
            ."Licensed producer,Elena,Brooks,elena@lonestar.example,30405060,TX\n"
            ."Agency employee,Omar,Haddad,omar@lonestar.example,,\n"
            ."Licensed producer,Bad,Npn,bad@lonestar.example,12,TX\n";

        Livewire::actingAs($this->principal)->test(AddPeople::class)
            ->call('importPeople', 'people.csv', $csv)
            ->assertCount('people.rows', 2)
            ->assertCount('people.problems', 1)
            ->call('reviewCost')
            ->assertSet('stage', 'list')
            ->assertSee('Fix or skip the rows from your CSV first.');
    }

    // Adding: paying

    public function test_paying_creates_the_people_sends_invitations_audits_and_emails_a_receipt(): void
    {
        Notification::fake();
        $this->updatesSucceed();

        $this->review([self::PRODUCER, self::EMPLOYEE])->call('pay')->assertRedirect(route('member.agency'));

        $update = $this->stripe->sent('POST', '/v1/subscriptions/sub_agency')[0];
        $this->assertSame([['id' => 'si_agency', 'quantity' => 5, 'price' => 'price_agency_founding_2to9']], $update['params']['items']);
        $this->assertSame('always_invoice', $update['params']['proration_behavior']);
        $this->assertSame('error_if_incomplete', $update['params']['payment_behavior']);
        $this->assertSame('pm_saved', $update['params']['default_payment_method']);
        $this->assertSame(now()->getTimestamp(), (int) $update['params']['proration_date']);
        $this->assertSame('prod_surcharge', $update['params']['add_invoice_items'][0]['price_data']['product']);
        $this->assertSame(231, (int) $update['params']['add_invoice_items'][0]['price_data']['unit_amount']);
        $this->assertNotNull($update['idempotency_key']);

        $elena = User::query()->where('email', 'elena@lonestar.example')->firstOrFail()->memberProfile;
        $omar = User::query()->where('email', 'omar@lonestar.example')->firstOrFail()->memberProfile;
        $this->assertSame($this->agency->id, $elena->agency_id);
        $this->assertSame('pending_review', $elena->license_verification?->value);
        $this->assertNull($omar->npn);
        $this->assertSame(InviteStatus::Invited, $elena->invite_status);
        $this->assertSame(5, $this->agency->fresh()->headcount);
        $this->assertSame(5, $this->agency->fresh()->billed_headcount);

        Queue::assertPushed(SendAgencyInvitation::class, 2);

        $audit = AuditLog::query()->where('action', 'agency_people_added')->sole();
        $this->assertSame(['people' => 2, 'amount' => 7950, 'surcharge' => 231, 'tier_from' => 'agency_2to9', 'invoice' => 'in_added'], $audit->details);

        Notification::assertSentTo($this->principal, AgencyPeopleAdded::class, function (AgencyPeopleAdded $receipt) {
            $mail = $receipt->toMail($this->principal)->render();

            return $receipt->total === 8181 && str_contains($mail, 'Credit card surcharge') && str_contains($mail, '$81.81') && ! str_contains($mail, 'Elena');
        });
    }

    public function test_crossing_a_tier_saves_the_new_tier(): void
    {
        $this->updatesSucceed();
        $people = [];

        foreach (range(1, 7) as $n) {
            $people[] = ['type' => 'agency_employee', 'first_name' => 'Person', 'last_name' => "No{$n}", 'email' => "person{$n}@lonestar.example", 'npn' => '', 'license_state' => ''];
        }

        $this->review($people)->call('pay')->assertRedirect(route('member.agency'));

        $this->assertSame(AgencyTier::AgencyPlus, $this->agency->fresh()->tier);
        $this->assertSame(RateType::Founding, $this->agency->fresh()->rate_type);
        $this->assertSame('agency_10to49', AuditLog::query()->where('action', 'agency_people_added')->sole()->details['tier_to']);
    }

    public function test_a_refused_payment_changes_nothing(): void
    {
        Notification::fake();
        $this->stripe->on('POST', '/v1/subscriptions/sub_agency', ['error' => ['type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'insufficient_funds', 'message' => 'Your card has insufficient funds.']], 402);

        $this->review()->call('pay')
            ->assertNoRedirect()
            ->assertSet('stage', 'review')
            ->assertSee('declined for insufficient funds. Nothing was charged.');

        $this->assertFalse(User::query()->where('email', 'elena@lonestar.example')->exists());
        $this->assertSame(3, $this->agency->fresh()->headcount);
        $this->assertSame(AgencyTier::Agency, $this->agency->fresh()->tier);
        $this->assertFalse(AuditLog::query()->where('action', 'agency_people_added')->exists());
        Queue::assertNothingPushed();
        Notification::assertNothingSent();
    }

    public function test_a_payment_that_needs_the_bank_s_confirmation_is_explained(): void
    {
        $this->stripe->on('POST', '/v1/subscriptions/sub_agency', ['error' => ['type' => 'card_error', 'code' => 'authentication_required', 'message' => 'This payment requires authentication.']], 402);

        $this->review()->call('pay')->assertNoRedirect()->assertSee('Your bank wants to confirm this payment');
        $this->assertFalse(User::query()->where('email', 'elena@lonestar.example')->exists());
    }

    public function test_a_different_amount_at_payment_is_shown_again_instead_of_charged(): void
    {
        $component = $this->review();
        $this->lines[1][1] = 60000;

        $component->call('pay')->assertNoRedirect()->assertSee('The amount is now');
        $this->assertSame([], $this->stripe->sent('POST', '/v1/subscriptions/sub_agency'));
    }

    public function test_a_change_that_would_credit_the_agency_charges_nothing(): void
    {
        $this->updatesSucceed();
        $this->lines = [['Unused time', -90000], ['Remaining time', 60000]];

        $this->review()
            ->assertSet('review.no_charge', true)
            ->assertSet('review.total', 0)
            ->assertSee('nothing is charged today and nothing is credited')
            ->call('pay')
            ->assertRedirect(route('member.agency'));

        $update = $this->stripe->sent('POST', '/v1/subscriptions/sub_agency')[0]['params'];
        $this->assertSame('none', $update['proration_behavior']);
        $this->assertArrayNotHasKey('payment_behavior', $update);
        $this->assertArrayNotHasKey('add_invoice_items', $update);
        $this->assertTrue(User::query()->where('email', 'elena@lonestar.example')->exists());
    }

    public function test_adding_needs_an_active_paid_agency_with_a_payment_method(): void
    {
        $this->agency->forceFill(['status' => AgencyStatus::PastDue])->saveQuietly();

        $this->review()->assertSet('stage', 'list')->assertSee('while the membership is active and paid');
    }

    // Removing

    public function test_a_removed_person_keeps_access_until_the_renewal_and_then_leaves(): void
    {
        Notification::fake();
        $this->updatesSucceed();
        $leaving = $this->people[0];
        $renewal = $this->agency->paid_through_at;

        $this->actingAs($this->principal)
            ->post(route('member.agency.remove', $leaving), ['reason' => 'left_agency'])
            ->assertRedirect(route('member.agency'));

        $leaving->refresh();
        $this->assertTrue($leaving->removal_effective_at->equalTo($renewal));
        $this->assertSame('left_agency', $leaving->removal_reason);
        $this->assertSame($this->agency->id, $leaving->agency_id);
        $this->assertFalse($leaving->hasRestrictedMembership());

        // Stripe bills only the people who stay from the renewal; nothing prorated now.
        $update = $this->stripe->sent('POST', '/v1/subscriptions/sub_agency')[0]['params'];
        $this->assertSame([['id' => 'si_agency', 'quantity' => 2, 'price' => 'price_agency_founding_2to9']], $update['items']);
        $this->assertSame('none', $update['proration_behavior']);

        $this->assertSame(['agency_id' => $this->agency->id, 'reason' => 'left_agency', 'effective_on' => $renewal->toDateString()], AuditLog::query()->where('action', 'agency_member_removal_scheduled')->sole()->details);
        Notification::assertSentTo($leaving->user, AgencyRemovalScheduled::class, function (AgencyRemovalScheduled $notice) use ($leaving) {
            $mail = $notice->toMail($leaving->user)->render();

            return str_contains($mail, $this->agency->paid_through_at->format('F j, Y')) && str_contains($mail, (string) config('portal.registration.individual_url'));
        });
        Notification::assertNotSentTo($this->principal, AgencyEndingAtRenewal::class);

        $this->get(route('member.agency'))->assertSee('Leaves '.$renewal->format('j M Y'));

        // Before the date nothing happens; on it the person is off the agency and inactive.
        $this->artisan('app:apply-agency-removals')->assertSuccessful();
        $this->assertSame($this->agency->id, $leaving->fresh()->agency_id);

        $this->travelTo($renewal->copy()->addMinute());
        $this->artisan('app:apply-agency-removals')->assertSuccessful();

        $leaving->refresh();
        $this->assertNull($leaving->agency_id);
        $this->assertSame(MemberStatus::Inactive, $leaving->status);
        $this->assertSame(PaymentStatus::Canceled, $leaving->payment_status);
        $this->assertTrue($leaving->hasRestrictedMembership());
        $this->assertSame(2, $this->agency->fresh()->headcount);
    }

    public function test_the_principal_cannot_be_removed_and_a_reason_is_required(): void
    {
        $this->actingAs($this->principal)
            ->post(route('member.agency.remove', $this->principal->memberProfile), ['reason' => 'left_agency'])
            ->assertSessionHas('agency_error');
        $this->assertNull($this->principal->memberProfile->fresh()->removal_effective_at);

        $this->post(route('member.agency.remove', $this->people[0]), ['reason' => 'because'])->assertSessionHasErrors('reason');
        $this->assertNull($this->people[0]->fresh()->removal_effective_at);

        // Someone else's person is a 404.
        $other = MemberProfile::factory()->create();
        $this->post(route('member.agency.remove', $other), ['reason' => 'left_agency'])->assertNotFound();
        $this->assertSame([], $this->stripe->sent('POST', '/v1/subscriptions/sub_agency'));
    }

    public function test_below_two_people_the_agency_ends_at_renewal_and_everyone_is_told(): void
    {
        Notification::fake();
        $this->updatesSucceed();

        $this->actingAs($this->principal)->post(route('member.agency.remove', $this->people[0]), ['reason' => 'role_changed']);
        Notification::assertNotSentTo($this->principal, AgencyEndingAtRenewal::class);

        $this->get(route('member.agency', ['remove' => $this->people[1]->id]))->assertSee('fewer than 2', false);
        $this->post(route('member.agency.remove', $this->people[1]), ['reason' => 'left_agency']);

        $last = collect($this->stripe->sent('POST', '/v1/subscriptions/sub_agency'))->last()['params'];
        $this->assertSame('true', (string) $last['cancel_at_period_end']);
        $this->assertTrue($this->agency->fresh()->cancel_at_period_end);

        Notification::assertSentTo($this->principal, AgencyEndingAtRenewal::class, function (AgencyEndingAtRenewal $notice) {
            $mail = $notice->toMail($this->principal)->render();

            return str_contains($mail, (string) config('portal.registration.individual_url'));
        });
        $this->get(route('member.agency'))->assertSee('will not renew');
    }

    public function test_a_scheduled_removal_can_be_canceled_before_the_renewal(): void
    {
        $this->updatesSucceed();
        $person = $this->people[0];

        $this->actingAs($this->principal)->post(route('member.agency.remove', $person), ['reason' => 'duplicate']);
        $this->delete(route('member.agency.keep', $person))->assertRedirect(route('member.agency'))->assertSessionHas('status');

        $this->assertNull($person->fresh()->removal_effective_at);
        $this->assertSame(['agency_id' => $this->agency->id], AuditLog::query()->where('action', 'agency_member_removal_canceled')->sole()->details);
        $last = collect($this->stripe->sent('POST', '/v1/subscriptions/sub_agency'))->last()['params'];
        $this->assertSame(3, (int) $last['items'][0]['quantity']);

        // After the date it is too late.
        $this->post(route('member.agency.remove', $person), ['reason' => 'duplicate']);
        $this->travelTo($this->agency->paid_through_at->copy()->addMinute());
        $this->delete(route('member.agency.keep', $person))->assertSessionHas('agency_error');
    }

    public function test_dropping_below_a_tier_lowers_the_price_from_the_renewal_and_keeps_founding(): void
    {
        $this->updatesSucceed();
        $this->agency->forceFill(['tier' => AgencyTier::AgencyPlus])->saveQuietly();

        $this->actingAs($this->principal)->post(route('member.agency.remove', $this->people[0]), ['reason' => 'left_agency']);

        $update = $this->stripe->sent('POST', '/v1/subscriptions/sub_agency')[0]['params'];
        $this->assertSame('price_agency_founding_2to9', $update['items'][0]['price']);
        $this->assertSame('none', $update['proration_behavior']);
        // The tier shown stays until the renewal (Stripe's subscription update tells the portal then).
        $this->assertSame(AgencyTier::AgencyPlus, $this->agency->fresh()->tier);
    }

    // Nova (I2)

    public function test_nova_shows_scheduled_removals_and_dunning_with_filters(): void
    {
        $this->updatesSucceed();
        $this->actingAs($this->principal)->post(route('member.agency.remove', $this->people[0]), ['reason' => 'left_agency']);

        $late = Agency::factory()->create(['status' => AgencyStatus::PastDue, 'past_due_since' => now()->subDays(3)]);
        $staff = $this->staff(Role::MembershipCoordinator);

        $fields = collect($this->actingAs($staff)->getJson('/nova-api/agencies?orderBy=id&orderByDirection=asc')->assertOk()->json('resources'))
            ->mapWithKeys(fn (array $row) => [$row['id']['value'] => collect($row['fields'])->pluck('value', 'name')]);
        $this->assertSame('1 on '.$this->agency->paid_through_at->toFormattedDateString(), $fields[$this->agency->id]['Scheduled Removals']);
        $this->assertStringContainsString('access until', (string) $fields[$late->id]['Dunning']);

        $filtered = fn (string $filter, mixed $value) => collect($this->getJson('/nova-api/agencies?filters='.base64_encode(json_encode([[$filter => $value]])))->assertOk()->json('resources'))->pluck('id.value')->all();
        $this->assertSame([$this->agency->id], $filtered('agency-scheduled-removals', ['scheduled' => true]));
        $this->assertSame([$late->id], $filtered('agency-dunning', 'grace'));

        $people = collect($this->getJson('/nova-api/member-profiles?filters='.base64_encode(json_encode([['member-removal-scheduled' => ['scheduled' => true]]])))->assertOk()->json('resources'))->pluck('id.value')->all();
        $this->assertSame([$this->people[0]->id], $people);
    }
}
