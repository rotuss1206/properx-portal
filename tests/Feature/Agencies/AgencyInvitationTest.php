<?php

namespace Tests\Feature\Agencies;

use App\Enums\AgencyTier;
use App\Enums\InviteStatus;
use App\Enums\ReminderType;
use App\Enums\Role;
use App\Http\Controllers\Security\TwoFactorSetupController;
use App\Jobs\SendAgencyInvitation;
use App\Member\NotificationFeed;
use App\Models\Agency;
use App\Models\AgencyInvitation;
use App\Models\AuditLog;
use App\Models\MemberProfile;
use App\Models\ReminderLog;
use App\Models\User;
use App\Notifications\AgencyEnrolledStaff;
use App\Notifications\AgencyInvitationEmail;
use App\Notifications\AgencyWelcome;
use App\Notifications\Member\AgencyInvitationNotice;
use App\Services\Agencies\AgencyInvitations;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Nova\Notifications\NovaNotification;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * Agency invitations (Agency spec 7, E1-E3): the emails after the enrollment, the one-time links,
 * accepting (password, two-factor, profile and consents), expired and used links with "Ask for a
 * new invitation", reminders on day 3 and day 7, and staff's "Resend invitation".
 */
class AgencyInvitationTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    private const RECEIPT = ['subtotal' => 47700, 'surcharge' => 1383, 'total' => 49083, 'tier' => 'agency_2to9', 'rate_type' => 'founding', 'headcount' => 3, 'partner_rate_applied' => false];

    private Agency $agency;

    private User $principal;

    private MemberProfile $producer;

    private MemberProfile $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->apiService();
        config(['portal.member_area.enabled' => true, 'portal.invitations.staff_email' => null]);
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00', 'America/New_York'));

        $this->agency = Agency::factory()->create(['tier' => AgencyTier::Agency, 'paid_through_at' => now()->addYear()]);
        $this->principal = $this->person(['first_name' => 'Dana', 'last_name' => 'Whitfield']);
        $this->agency->update(['principal_user_id' => $this->principal->id]);
        $this->producer = $this->person(['first_name' => 'Marcus', 'last_name' => 'Reyes', 'npn' => '1234567', 'license_state' => 'TX'])->memberProfile;
        $this->employee = $this->person(['first_name' => 'Priya', 'last_name' => 'Nair'], employee: true)->memberProfile;
        $this->agency->refresh();
    }

    /**
     * Someone on the agency who has not set up their account (no password they know, no 2FA).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function person(array $attributes, bool $employee = false): User
    {
        $user = User::factory()->create()->assignRole(Role::Member->value);
        $factory = MemberProfile::factory()->inAgency($this->agency, InviteStatus::Invited);
        $factory = $employee ? $factory->employee() : $factory;
        $factory->for($user)->create($attributes);

        return $user->load('memberProfile');
    }

    private function invitations(): AgencyInvitations
    {
        return app(AgencyInvitations::class);
    }

    /**
     * The token of the last email of this kind to this person (Notification::fake()).
     */
    private function tokenFor(User $user, string $class = AgencyInvitationEmail::class): string
    {
        $url = Notification::sent($user, $class)->last()->toMail($user)->actionUrl;

        return basename(parse_url($url, PHP_URL_PATH));
    }

    private function inviteEveryone(): void
    {
        foreach ($this->agency->members()->pluck('id') as $id) {
            SendAgencyInvitation::dispatch($id, $id === $this->principal->memberProfile->id ? self::RECEIPT : null);
        }
    }

    private function giveTwoFactor(User $user): void
    {
        $signedIn = auth()->id() === $user->id;
        $user->forceFill([
            'two_factor_secret' => encrypt(app(TwoFactorAuthenticationProvider::class)->generateSecretKey()),
            'two_factor_recovery_codes' => encrypt(json_encode(['a-b'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        // The guard keeps the instance it signed in.
        if ($signedIn) {
            $this->actingAs($user->fresh());
        }
    }

    // Sending (E1)

    public function test_everyone_gets_one_invitation_and_the_principal_the_welcome_with_the_receipt(): void
    {
        Notification::fake();
        $this->inviteEveryone();
        $this->inviteEveryone(); // a repeated enrollment (browser and webhook) or a retried job

        Notification::assertSentToTimes($this->principal, AgencyWelcome::class, 1);
        Notification::assertNotSentTo($this->principal, AgencyInvitationEmail::class);
        Notification::assertSentToTimes($this->producer->user, AgencyInvitationEmail::class, 1);
        Notification::assertSentToTimes($this->employee->user, AgencyInvitationEmail::class, 1);
        $this->assertSame(3, AgencyInvitation::query()->count());

        $token = $this->tokenFor($this->producer->user);
        $link = AgencyInvitation::query()->where('member_profile_id', $this->producer->id)->sole();
        $this->assertSame(hash('sha256', $token), $link->token_hash);
        $this->assertDatabaseMissing('agency_invitations', ['token_hash' => $token]);
        $this->assertSame(now()->addDays(14)->getTimestamp(), $link->expires_at->getTimestamp());
        $this->assertSame(InviteStatus::Invited, $this->producer->fresh()->invite_status);
        $this->assertSame(3, AuditLog::query()->where('action', 'agency_invitation_sent')->count());
    }

    public function test_the_emails_carry_no_names_and_the_welcome_shows_the_receipt(): void
    {
        Notification::fake();
        $this->inviteEveryone();

        $welcome = Notification::sent($this->principal, AgencyWelcome::class)->sole()->toMail($this->principal);
        $html = (string) $welcome->render();
        $this->assertSame('Welcome to ProPerX Alliance: your agency is enrolled', $welcome->subject);
        foreach (['Agency, Founding rate', '$477', 'Credit card surcharge', '$13.83', 'Total paid', '$490.83', 'Renews on', now()->addYear()->format('F j, Y')] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        $invitation = Notification::sent($this->employee->user, AgencyInvitationEmail::class)->sole()->toMail($this->employee->user);
        $text = $invitation->subject.' '.$invitation->render();
        $this->assertSame('Your agency has enrolled you in ProPerX Alliance', $invitation->subject);
        foreach (['Priya', 'Nair', 'Dana', 'Whitfield', $this->agency->legal_name, $this->employee->user->email] as $private) {
            $this->assertStringNotContainsString($private, $text);
            $this->assertStringNotContainsString($private, $html);
        }
    }

    public function test_staff_hear_of_a_new_agency_in_nova_and_by_email_when_an_address_is_set(): void
    {
        Notification::fake();
        $director = $this->staff(Role::ExecutiveDirector);

        $this->invitations()->announceEnrollment($this->agency);
        Notification::assertSentTo($director, NovaNotification::class, fn (NovaNotification $notification) => str_contains($notification->toArray($director)['message'], 'New agency enrolled'));
        Notification::assertNotSentTo(new AnonymousNotifiable, AgencyEnrolledStaff::class);

        config(['portal.invitations.staff_email' => 'staff@properx.test']);
        $this->invitations()->announceEnrollment($this->agency);
        Notification::assertSentOnDemand(AgencyEnrolledStaff::class, fn ($notification, $channels, AnonymousNotifiable $to) => $to->routes['mail'] === 'staff@properx.test');
    }

    // Accepting (E2)

    public function test_a_producer_sets_a_password_then_two_factor_then_checks_the_profile_and_consents(): void
    {
        Notification::fake();
        $this->inviteEveryone();
        $token = $this->tokenFor($this->producer->user);
        $user = $this->producer->user;

        $this->get(route('join.invitation', $token))->assertOk()->assertSee('Set up your account')->assertSee($user->email);

        $this->post(route('join.invitation.password', $token), ['password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');
        $this->assertGuest();

        $this->post(route('join.invitation.password', $token), ['password' => 'a-long-new-password', 'password_confirmation' => 'a-long-new-password'])
            ->assertRedirect(route('join.invitation.complete'));
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(password_verify('a-long-new-password', $user->fresh()->password));
        $this->assertNotNull(session(TwoFactorSetupController::FRESH_LOGIN_KEY));

        // Two-factor setup first, then the last step; the portal stays closed until both are done.
        $this->get(route('join.invitation.complete'))->assertRedirect(route('security.two-factor'));
        $this->giveTwoFactor($user);
        $this->get(route('member.dashboard'))->assertRedirect(route('join.invitation.complete'));

        $this->get(route('join.invitation.complete'))->assertOk()
            ->assertSee('National Producer Number')->assertSee('value="1234567"', false)
            ->assertSee('Terms of Service')->assertSee('Privacy Policy');

        $this->post(route('join.invitation.accept'), ['first_name' => 'Marcus', 'last_name' => 'Reyes', 'npn' => '', 'license_state' => 'TX'])
            ->assertSessionHasErrors(['npn', 'consents.terms', 'consents.privacy']);

        $this->post(route('join.invitation.accept'), [
            'first_name' => 'Marcus', 'last_name' => 'Reyes', 'phone' => '', 'npn' => '1234567', 'license_state' => 'OK',
            'consents' => ['terms' => '1', 'privacy' => '1'],
        ])->assertRedirect(route('member.dashboard'));

        $profile = $this->producer->fresh();
        $this->assertSame(InviteStatus::AccountActive, $profile->invite_status);
        $this->assertSame('OK', $profile->license_state);
        $link = $profile->invitations()->sole();
        $this->assertSame(['terms', 'privacy'], $link->consents['keys']);
        $this->assertSame(config('portal.invitations.consent_version'), $link->consents['version']);
        $this->assertSame(['terms', 'privacy'], AuditLog::query()->where('action', 'agency_invitation_accepted')->sole()->details['consents']);

        $this->get(route('member.dashboard'))->assertOk();
        // Spent: the link now says the account is set up.
        $this->get(route('join.invitation', $token))->assertGone()->assertSee('Your account is already set up')->assertDontSee('Ask for a new invitation');
    }

    public function test_an_employee_checks_contacts_only(): void
    {
        Notification::fake();
        $this->inviteEveryone();
        $token = $this->tokenFor($this->employee->user);

        $this->post(route('join.invitation.password', $token), ['password' => 'a-long-new-password', 'password_confirmation' => 'a-long-new-password']);
        $this->giveTwoFactor($this->employee->user);

        $this->get(route('join.invitation.complete'))->assertOk()->assertDontSee('National Producer Number');
        $this->post(route('join.invitation.accept'), [
            'first_name' => 'Priya', 'last_name' => 'Nair', 'phone' => '(512) 555-0142', 'npn' => '999',
            'consents' => ['terms' => '1', 'privacy' => '1'],
        ])->assertRedirect(route('member.dashboard'));

        $profile = $this->employee->fresh();
        $this->assertSame(InviteStatus::AccountActive, $profile->invite_status);
        $this->assertNull($profile->npn);
    }

    public function test_the_principal_is_done_after_the_password_and_two_factor(): void
    {
        Notification::fake();
        $this->inviteEveryone();
        $token = $this->tokenFor($this->principal, AgencyWelcome::class);

        $this->post(route('join.invitation.password', $token), ['password' => 'a-long-new-password', 'password_confirmation' => 'a-long-new-password'])
            ->assertRedirect(route('member.dashboard'));

        $this->assertSame(InviteStatus::AccountActive, $this->principal->memberProfile->fresh()->invite_status);
        $this->get(route('member.dashboard'))->assertRedirect(route('security.two-factor'));
        $this->giveTwoFactor($this->principal);
        $this->get(route('member.dashboard'))->assertOk();
    }

    public function test_a_used_link_cannot_set_the_password_again(): void
    {
        Notification::fake();
        $this->inviteEveryone();
        $token = $this->tokenFor($this->producer->user);
        $this->post(route('join.invitation.password', $token), ['password' => 'a-long-new-password', 'password_confirmation' => 'a-long-new-password']);
        auth()->logout();

        $this->get(route('join.invitation', $token))->assertGone()->assertSee('This link has already been used')->assertSee('Ask for a new invitation');
        $this->post(route('join.invitation.password', $token), ['password' => 'another-password-1', 'password_confirmation' => 'another-password-1'])->assertGone();
        $this->assertTrue(password_verify('a-long-new-password', $this->producer->user->fresh()->password));
    }

    public function test_an_unknown_link_says_so_without_a_button(): void
    {
        $this->get(route('join.invitation', str_repeat('a', 48)))->assertGone()->assertSee("This link can't be used", false)->assertDontSee('Ask for a new invitation');
    }

    public function test_an_expired_link_asks_for_a_new_invitation_once(): void
    {
        Notification::fake();
        $director = $this->staff(Role::ExecutiveDirector);
        $this->inviteEveryone();
        $token = $this->tokenFor($this->producer->user);
        $this->travel(15)->days();

        $this->get(route('join.invitation', $token))->assertGone()->assertSee('This invitation has expired')->assertSee('Ask for a new invitation');
        $this->post(route('join.invitation.password', $token), ['password' => 'a-long-new-password', 'password_confirmation' => 'a-long-new-password'])->assertGone();

        $this->post(route('join.invitation.request', $token))->assertGone()->assertSee("We've asked for a new invitation", false);
        $this->post(route('join.invitation.request', $token))->assertGone()->assertSee('A new invitation has been asked for');

        Notification::assertSentToTimes($this->principal, AgencyInvitationNotice::class, 1);
        Notification::assertSentTo($this->principal, AgencyInvitationNotice::class, fn (AgencyInvitationNotice $notice) => $notice->kind === AgencyInvitationNotice::REQUESTED && $notice->profileIds === [$this->producer->id]);
        Notification::assertSentToTimes($director, NovaNotification::class, 1);
        $this->assertSame(1, AuditLog::query()->where('action', 'agency_invitation_requested')->count());
    }

    public function test_the_principal_sees_who_asked_under_the_bell(): void
    {
        $this->principal->notifyNow(new AgencyInvitationNotice(AgencyInvitationNotice::REQUESTED, [$this->producer->id]));
        $this->principal->notifyNow(new AgencyInvitationNotice(AgencyInvitationNotice::NOT_ACCEPTED, [$this->producer->id, $this->employee->id]));

        $titles = NotificationFeed::latest($this->principal)->pluck('title')->all();
        $this->assertContains('Marcus Reyes asked for a new invitation', $titles);
        $this->assertContains('Not yet set up: Marcus Reyes, Priya Nair', $titles);
    }

    // Reminders (E3)

    public function test_reminders_go_on_day_3_and_day_7_once_and_the_second_is_copied_to_the_principal(): void
    {
        Notification::fake();
        $this->inviteEveryone();
        $first = $this->tokenFor($this->producer->user);

        $this->travel(2)->days();
        $this->artisan('app:send-invitation-reminders')->assertSuccessful();
        Notification::assertSentToTimes($this->producer->user, AgencyInvitationEmail::class, 1);

        $this->travel(1)->days();
        $this->artisan('app:send-invitation-reminders')->assertSuccessful();
        $this->artisan('app:send-invitation-reminders')->assertSuccessful();
        Notification::assertSentToTimes($this->producer->user, AgencyInvitationEmail::class, 2);
        Notification::assertSentToTimes($this->employee->user, AgencyInvitationEmail::class, 2);
        // The principal's own invitation is reminded too, never copied to themselves.
        Notification::assertSentToTimes($this->principal, AgencyInvitationEmail::class, 1);
        Notification::assertNotSentTo($this->principal, AgencyInvitationNotice::class);

        $reminder = Notification::sent($this->producer->user, AgencyInvitationEmail::class)->last()->toMail($this->producer->user);
        $this->assertSame('Reminder: set up your ProPerX Alliance member account', $reminder->subject);
        $second = $this->tokenFor($this->producer->user);
        $this->assertNotSame($first, $second);
        // Both links of the invitation work and expire together.
        $this->get(route('join.invitation', $first))->assertOk();
        $this->get(route('join.invitation', $second))->assertOk();
        $this->assertSame(1, AgencyInvitation::query()->whereNotNull('reminder_of_id')->distinct()->count('expires_at'));

        // The employee accepts in between: no more reminders for them.
        $this->post(route('join.invitation.password', $this->tokenFor($this->employee->user)), ['password' => 'a-long-new-password', 'password_confirmation' => 'a-long-new-password']);
        auth()->logout();
        $this->employee->forceFill(['invite_status' => InviteStatus::AccountActive])->save();

        $this->travel(4)->days();
        $this->artisan('app:send-invitation-reminders')->assertSuccessful();
        $this->artisan('app:send-invitation-reminders')->assertSuccessful();
        Notification::assertSentToTimes($this->producer->user, AgencyInvitationEmail::class, 3);
        Notification::assertSentToTimes($this->employee->user, AgencyInvitationEmail::class, 2);
        Notification::assertSentToTimes($this->principal, AgencyInvitationNotice::class, 1);
        Notification::assertSentTo($this->principal, AgencyInvitationNotice::class, fn (AgencyInvitationNotice $notice) => $notice->kind === AgencyInvitationNotice::NOT_ACCEPTED && $notice->profileIds === [$this->producer->id]);

        $logs = ReminderLog::query()->where('user_id', $this->producer->user_id)->orderBy('id')->pluck('reminder_type')->all();
        $this->assertSame([ReminderType::InvitationDay3, ReminderType::InvitationDay7], $logs);

        // Nothing after the invitation has expired.
        $this->travel(10)->days();
        $this->artisan('app:send-invitation-reminders')->assertSuccessful();
        Notification::assertSentToTimes($this->producer->user, AgencyInvitationEmail::class, 3);
    }

    public function test_a_late_run_sends_only_the_day_7_reminder(): void
    {
        Notification::fake();
        $this->inviteEveryone();

        $this->travel(8)->days();
        $this->artisan('app:send-invitation-reminders')->assertSuccessful();

        Notification::assertSentToTimes($this->producer->user, AgencyInvitationEmail::class, 2);
        $this->assertSame([ReminderType::InvitationDay7], ReminderLog::query()->where('user_id', $this->producer->user_id)->pluck('reminder_type')->all());
    }

    // Resend (Nova)

    public function test_staff_resend_an_invitation_and_the_old_link_stops_working(): void
    {
        Notification::fake();
        $this->inviteEveryone();
        $old = $this->tokenFor($this->producer->user);

        $coordinator = $this->staff(Role::MembershipCoordinator);
        $this->actingAs($coordinator)
            ->postJson('/nova-api/member-profiles/action?action=resend-invitation', ['resources' => (string) $this->producer->id])
            ->assertOk()
            ->assertJson(['message' => 'Invitation sent.']);
        auth()->logout();

        $new = $this->tokenFor($this->producer->user);
        $this->assertNotSame($old, $new);
        $this->get(route('join.invitation', $old))->assertGone()->assertSee('This link has been replaced');
        $this->get(route('join.invitation', $new))->assertOk();
        $this->assertSame('resend', AuditLog::query()->where('action', 'agency_invitation_sent')->latest('id')->first()->details['kind']);
    }

    public function test_resend_leaves_active_accounts_alone_and_is_staff_only(): void
    {
        Notification::fake();
        $this->producer->forceFill(['invite_status' => InviteStatus::AccountActive])->save();

        $this->actingAs($this->staff(Role::MembershipCoordinator))
            ->postJson('/nova-api/member-profiles/action?action=resend-invitation', ['resources' => (string) $this->producer->id])
            ->assertOk()
            ->assertJsonMissing(['message' => 'Invitation sent.']);
        Notification::assertNothingSentTo($this->producer->user);

        $this->giveTwoFactor($this->principal);
        $this->principal->memberProfile->forceFill(['invite_status' => InviteStatus::AccountActive])->save();
        $this->actingAs($this->principal)
            ->postJson('/nova-api/member-profiles/action?action=resend-invitation', ['resources' => (string) $this->employee->id])
            ->assertStatus(403);
    }
}
