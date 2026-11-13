<?php

namespace Tests\Feature\Member;

use App\Enums\AgencyStatus;
use App\Enums\AgencyTier;
use App\Enums\AgencyType;
use App\Enums\InviteStatus;
use App\Enums\LicenseVerification;
use App\Enums\MemberStatus;
use App\Enums\RateType;
use App\Enums\Role;
use App\Jobs\SyncAgencyStripeCustomer;
use App\Models\Agency;
use App\Models\AgencyInvitation;
use App\Models\AuditLog;
use App\Models\MemberProfile;
use App\Models\User;
use App\Notifications\AgencyInvitationEmail;
use App\Services\Stripe\BillingReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * /member/agency, the principal's agency (Agency spec 8.1, G1-G2), and staff's Deactivate /
 * Reactivate person in Nova (I1).
 */
class AgencyAreaTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    private const ADDRESS = '400 Congress Ave, Suite 210, Austin, TX 78701';

    private Agency $agency;

    private User $principal;

    private MemberProfile $producer;

    private MemberProfile $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        config(['portal.member_area.enabled' => true]);

        $this->agency = Agency::factory()->create([
            'legal_name' => 'Lone Star Benefits LLC',
            'tier' => AgencyTier::Agency,
            'rate_type' => RateType::Founding,
            'status' => AgencyStatus::Active,
            'paid_through_at' => now()->addMonths(10),
            'fein' => '12-3456789',
            'agency_npn' => null,
            'dba' => null,
            'website' => null,
            'legal_address' => self::ADDRESS,
            'billing_email' => 'billing@lonestar.example',
            'phone' => '(512) 555-0142',
            'agency_type' => AgencyType::HealthLife,
        ]);
        $this->principal = $this->person(['first_name' => 'Dana', 'last_name' => 'Whitfield'], InviteStatus::AccountActive);
        $this->agency->update(['principal_user_id' => $this->principal->id]);
        $this->producer = $this->person(['first_name' => 'Marcus', 'last_name' => 'Reyes'])->memberProfile;
        $this->employee = $this->person(['first_name' => 'Priya', 'last_name' => 'Nair'], InviteStatus::AccountActive, employee: true)->memberProfile;
        $this->agency->refresh();
        $this->principal->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function person(array $attributes, InviteStatus $invite = InviteStatus::Invited, bool $employee = false): User
    {
        $user = User::factory()->withTwoFactor()->create()->assignRole(Role::Member->value);
        $factory = MemberProfile::factory()->inAgency($this->agency, $invite);
        ($employee ? $factory->employee() : $factory)->for($user)->create($attributes);

        return $user->load('memberProfile');
    }

    /**
     * @return array<string, mixed>
     */
    private function details(array $changes = []): array
    {
        return $changes + [
            'legal_name' => 'Lone Star Benefits LLC',
            'dba' => 'Lone Star',
            'agency_type' => AgencyType::HealthLife->value,
            'agency_npn' => '',
            'legal_address' => ['line1' => '400 Congress Ave', 'line2' => 'Suite 210', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701'],
            'mailing_same_as_legal' => '1',
            'phone' => '(512) 555-0142',
            'website' => 'www.lonestar.example',
            'billing_email' => 'billing@lonestar.example',
        ];
    }

    // Access and the page

    public function test_only_the_principal_opens_my_agency(): void
    {
        $this->actingAs($this->principal)->get(route('member.agency'))->assertOk();

        foreach ([$this->producer->user, $this->employee->user, $this->member()] as $other) {
            $this->actingAs($other)->get(route('member.agency'))->assertNotFound();
            $this->patch(route('member.agency.update'), $this->details(['legal_name' => 'Taken over']))->assertNotFound();
        }

        $this->assertSame('Lone Star Benefits LLC', $this->agency->fresh()->legal_name);
    }

    public function test_the_summary_and_the_people_with_their_statuses(): void
    {
        $this->producer->forceFill(['license_verification' => LicenseVerification::NeedsAttention, 'license_verification_note' => 'The NPN does not match the state.'])->save();
        $this->employee->forceFill(['status' => MemberStatus::Inactive])->saveQuietly();

        $response = $this->actingAs($this->principal)->get(route('member.agency'))->assertOk();

        $response->assertSeeInOrder(['Agency', 'Lone Star Benefits LLC', 'Active', 'Paid through', 'Tier and rate', 'Agency', '$159 per person a year · Founding rate', 'People', '3']);
        $response->assertSeeInOrder(['Dana Whitfield', 'Principal (you)', 'Producer', 'Account active']);
        $response->assertSeeInOrder(['Marcus Reyes', 'Producer', 'Invitation sent', 'Needs attention', 'The NPN does not match the state.', 'Active', 'Resend invitation']);
        $response->assertSeeInOrder(['Priya Nair', 'Employee', 'Account active', 'Not applicable', 'Inactive']);
        $response->assertSee(route('member.agency.add'), false)->assertDontSee('coming soon');

        // Filters by status.
        $this->get(route('member.agency', ['filter' => 'not_accepted']))->assertSee('Marcus Reyes')->assertDontSee('Priya Nair');
        $this->get(route('member.agency', ['filter' => 'inactive']))->assertSee('Priya Nair')->assertDontSee('Marcus Reyes');
        $this->get(route('member.agency', ['filter' => 'needs_attention']))->assertSee('Marcus Reyes')->assertDontSee('Principal (you)');
    }

    public function test_switching_people_tabs_keeps_the_layout(): void
    {
        $this->producer->forceFill(['invite_status' => InviteStatus::AccountActive])->save();

        $response = $this->actingAs($this->principal)->get(route('member.agency', ['filter' => 'not_accepted']))->assertOk();

        // An empty group says so inside the same list area; chips.js swaps the chips and the list.
        $response->assertSeeInOrder(['data-ppx-chips-area', 'No people in this group'], false);
        $response->assertSee('data-ppx-chips-swap="people-chips"', false)
            ->assertSee('data-ppx-chips-swap="people"', false)
            // Each chip carries its label for the bold, invisible copy that fixes its width.
            ->assertSee('portal/chips.js?v=', false);
        $this->assertMatchesRegularExpression('#<span class="ppx-chips__text" data-label="(Not set up \(\d+\))">\1</span>#', $response->getContent());
    }

    // Resend invitation

    public function test_the_principal_resends_an_invitation_at_most_once_an_hour(): void
    {
        Notification::fake();

        $this->actingAs($this->principal)->from(route('member.agency'))
            ->post(route('member.agency.resend', $this->producer))
            ->assertRedirect(route('member.agency'))
            ->assertSessionHas('status', 'A new invitation was sent to Marcus Reyes. Earlier links no longer work.');

        Notification::assertSentToTimes($this->producer->user, AgencyInvitationEmail::class, 1);
        $entry = AuditLog::query()->where('action', 'agency_invitation_sent')->sole();
        $this->assertSame($this->principal->id, $entry->user_id);
        $this->assertSame('resend', $entry->details['kind']);

        // Again at once: refused, nothing sent.
        $this->from(route('member.agency'))->post(route('member.agency.resend', $this->producer))
            ->assertSessionHas('agency_error');
        Notification::assertSentToTimes($this->producer->user, AgencyInvitationEmail::class, 1);
        $this->get(route('member.agency'))->assertSee('Sent recently');

        // An hour later it may go again.
        $this->travel(61)->minutes();
        $this->from(route('member.agency'))->post(route('member.agency.resend', $this->producer))->assertSessionHas('status');
        Notification::assertSentToTimes($this->producer->user, AgencyInvitationEmail::class, 2);
        $this->assertSame(1, AgencyInvitation::query()->where('member_profile_id', $this->producer->id)->whereNull('revoked_at')->count());
    }

    public function test_no_resend_for_set_up_accounts_the_principal_or_other_agencies(): void
    {
        Notification::fake();
        $other = $this->agency(2)->members()->where('agency_role', 'member')->first();

        $this->actingAs($this->principal);
        $this->post(route('member.agency.resend', $this->employee))->assertNotFound();
        $this->post(route('member.agency.resend', $this->principal->memberProfile))->assertNotFound();
        $this->post(route('member.agency.resend', $other))->assertNotFound();

        Notification::assertNothingSent();
    }

    // Agency details

    public function test_the_details_form_reads_the_stored_address_and_shows_the_fein_read_only(): void
    {
        $response = $this->actingAs($this->principal)->get(route('member.agency', ['edit' => 'details']))->assertOk();

        $response->assertSee('value="400 Congress Ave"', false)
            ->assertSee('value="Suite 210"', false)
            ->assertSee('value="Austin"', false)
            ->assertSee('<option value="TX" selected>', false)
            ->assertSee('value="78701"', false)
            ->assertSee('12-3456789')
            ->assertSee('Contact ProPerX to change the FEIN')
            ->assertDontSee('name="fein"', false);
    }

    public function test_the_principal_saves_the_details_but_never_the_fein(): void
    {
        Queue::fake();
        $this->agency->forceFill(['stripe_customer_id' => 'cus_agency'])->saveQuietly();

        $this->actingAs($this->principal)->patch(route('member.agency.update'), $this->details([
            'legal_name' => 'Lone Star Benefits Group LLC',
            'billing_email' => 'Invoices@LoneStar.example',
            'mailing_same_as_legal' => '0',
            'mailing_address' => ['line1' => 'PO Box 12', 'line2' => '', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78702'],
            'fein' => '98-7654321',
        ]))->assertRedirect(route('member.agency'))->assertSessionHas('status', 'Agency details saved.');

        $agency = $this->agency->fresh();
        $this->assertSame('Lone Star Benefits Group LLC', $agency->legal_name);
        $this->assertSame('invoices@lonestar.example', $agency->billing_email);
        $this->assertSame('https://www.lonestar.example', $agency->website);
        $this->assertSame('PO Box 12, Austin, TX 78702', $agency->mailing_address); // a mailing address may be a P.O. box
        $this->assertSame(self::ADDRESS, $agency->legal_address);
        $this->assertSame('12-3456789', $agency->fein);

        $entry = AuditLog::query()->where('action', 'agency_updated')->latest('id')->first();
        $this->assertSame($this->principal->id, $entry->user_id);
        $this->assertEqualsCanonicalizing(['legal_name', 'dba', 'mailing_address', 'mailing_same_as_legal', 'website', 'billing_email'], $entry->details['fields']);
        $this->assertStringNotContainsString('lonestar', strtolower(json_encode($entry->details)));

        Queue::assertPushed(SyncAgencyStripeCustomer::class, fn ($job) => $job->agencyId === $agency->id);
    }

    public function test_the_billing_email_reaches_the_stripe_customer(): void
    {
        config(['services.stripe.secret' => 'sk_test_fake']);
        $this->agency->forceFill(['stripe_customer_id' => 'cus_agency', 'billing_email' => 'invoices@lonestar.example'])->saveQuietly();
        $this->stripe->on('POST', '/v1/customers/cus_agency', ['id' => 'cus_agency', 'object' => 'customer']);

        (new SyncAgencyStripeCustomer($this->agency->id))->handle(app(BillingReader::class));

        $this->assertSame(['email' => 'invoices@lonestar.example', 'name' => 'Lone Star Benefits LLC'], $this->stripe->sent('POST', '/v1/customers/cus_agency')[0]['params']);
    }

    public function test_no_stripe_update_when_neither_the_email_nor_the_name_changed(): void
    {
        Queue::fake();
        $this->agency->forceFill(['stripe_customer_id' => 'cus_agency'])->saveQuietly();

        $this->actingAs($this->principal)->patch(route('member.agency.update'), $this->details(['phone' => '(512) 555-0199']))->assertSessionHasNoErrors();

        Queue::assertNotPushed(SyncAgencyStripeCustomer::class);
    }

    public function test_the_details_follow_screen_3s_rules(): void
    {
        $this->actingAs($this->principal)->patch(route('member.agency.update'), $this->details([
            'legal_name' => '',
            'legal_address' => ['line1' => 'P.O. Box 9', 'line2' => '', 'city' => 'Austin', 'state' => 'ZZ', 'zip' => '787'],
            'phone' => '555',
            'billing_email' => 'not-an-email',
            'website' => 'my site',
        ]))->assertRedirect(route('member.agency', ['edit' => 'details']))
            ->assertSessionHasErrors(['legal_name', 'legal_address.line1', 'legal_address.state', 'legal_address.zip', 'phone', 'billing_email', 'website']);

        $this->assertSame('Lone Star Benefits LLC', $this->agency->fresh()->legal_name);
    }

    // Nova: roster and Deactivate / Reactivate person

    public function test_staff_deactivate_and_reactivate_an_agency_person_but_never_the_principal(): void
    {
        $coordinator = $this->staff(Role::MembershipCoordinator);
        $run = fn (string $action, MemberProfile $profile, array $fields = []) => $this->actingAs($coordinator)
            ->postJson("/nova-api/member-profiles/action?action={$action}", ['resources' => [$profile->id]] + $fields);

        // The reason is required, and only one from the list.
        $run('deactivate-agency-person', $this->producer)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $run('deactivate-agency-person', $this->producer, ['reason' => 'He moved to Dallas'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame(MemberStatus::Active, $this->producer->fresh()->status);

        $run('deactivate-agency-person', $this->producer, ['reason' => 'left_agency'])->assertOk()->assertJson(['message' => '1 person updated.']);
        $this->assertSame(MemberStatus::Inactive, $this->producer->fresh()->status);
        $this->assertTrue($this->producer->fresh()->hasRestrictedMembership());
        $this->assertSame(['agency_id' => $this->agency->id, 'reason' => 'left_agency'], AuditLog::query()->where('action', 'agency_member_deactivated')->sole()->details);
        $this->assertFalse(AuditLog::query()->where('action', 'profile_updated')->where('resource_id', $this->producer->id)->exists());

        $run('deactivate-agency-person', $this->principal->memberProfile, ['reason' => 'other'])->assertOk()->assertJsonStructure(['danger']);
        $this->assertSame(MemberStatus::Active, $this->principal->memberProfile->fresh()->status);

        $run('reactivate-agency-person', $this->producer)->assertOk();
        $this->assertSame(MemberStatus::Active, $this->producer->fresh()->status);
        $this->assertTrue(AuditLog::query()->where('action', 'agency_member_reactivated')->exists());

        // Members cannot.
        $this->actingAs($this->principal)->postJson('/nova-api/member-profiles/action?action=deactivate-agency-person', ['resources' => [$this->producer->id], 'reason' => 'other'])->assertForbidden();
    }

    public function test_the_nova_roster_shows_invitations_and_the_agencys_payment_status(): void
    {
        $this->agency->forceFill(['status' => AgencyStatus::PastDue])->saveQuietly();

        $rows = $this->actingAs($this->staff(Role::MembershipCoordinator))
            ->getJson("/nova-api/member-profiles?viaResource=agencies&viaResourceId={$this->agency->id}&viaRelationship=members&relationshipType=hasMany")
            ->assertOk()
            ->json('resources');

        $marcus = collect($rows)->first(fn ($row) => collect($row['fields'])->firstWhere('name', 'Name')['value'] === 'Marcus Reyes');
        $fields = collect($marcus['fields'])->keyBy('name');
        $this->assertSame('Invitation sent', $fields['Invite Status']['value']);
        $this->assertSame('Past due', $fields['Agency Payment']['label']);
    }
}
