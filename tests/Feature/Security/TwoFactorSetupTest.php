<?php

namespace Tests\Feature\Security;

use App\Enums\Role;
use App\Http\Controllers\Security\TwoFactorSetupController;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * The first-time two-factor setup: nobody gets past it, and it finishes only with a right code and
 * the recovery codes acknowledged.
 */
class TwoFactorSetupTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        config(['portal.member_area.enabled' => true]);
    }

    private function freshlySignedIn(User $user): static
    {
        return $this->actingAs($user)->withSession([TwoFactorSetupController::FRESH_LOGIN_KEY => time()]);
    }

    private function currentCode(User $user): string
    {
        return app(Google2FA::class)->getCurrentOtp(decrypt($user->fresh()->two_factor_secret));
    }

    public function test_people_without_two_factor_are_sent_to_the_setup(): void
    {
        $member = $this->member(withTwoFactor: false);

        $this->actingAs($member)->get('/member')->assertRedirect(route('security.two-factor'));
        $this->get('/nova/dashboards/my-membership')->assertRedirect(route('security.two-factor'));
        $this->get('/nova/user-security')->assertRedirect(route('security.two-factor'));

        $staff = $this->staff(Role::MembershipCoordinator, withTwoFactor: false);

        $this->actingAs($staff)->get('/nova/dashboards/main')->assertRedirect(route('security.two-factor'));
        $this->get('/nova/resources/members')->assertRedirect(route('security.two-factor'));
    }

    public function test_inertia_visits_leave_for_the_setup_with_a_full_page_load(): void
    {
        $version = app(HandleInertiaRequests::class)->version(request());

        $this->actingAs($this->staff(Role::ExecutiveDirector, withTwoFactor: false))
            ->get('/nova/dashboards/main', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', route('security.two-factor'));
    }

    public function test_guests_go_to_sign_in_and_signing_out_stays_open(): void
    {
        $this->get(route('security.two-factor'))->assertRedirect();
        $this->assertGuest();

        $member = $this->member(withTwoFactor: false);
        $this->actingAs($member)->post(route('nova.logout'))->assertRedirect();
        $this->assertGuest();
    }

    public function test_right_after_signing_in_the_password_is_not_asked_again(): void
    {
        $member = $this->member(withTwoFactor: false);

        $this->freshlySignedIn($member)->get(route('security.two-factor'))
            ->assertOk()
            ->assertSee('Google Authenticator')
            ->assertSee('Microsoft Authenticator')
            ->assertSee('1Password')
            ->assertSee('aria-current="step"', false)
            ->assertDontSee('name="password"', false);

        $this->post(route('security.two-factor.start'))->assertRedirect(route('security.two-factor.scan'));

        $this->assertNotNull($member->fresh()->two_factor_secret);
        $this->assertNull($member->fresh()->two_factor_confirmed_at);
    }

    public function test_an_old_session_must_confirm_the_password_and_sees_no_secret_before_that(): void
    {
        $member = $this->member(withTwoFactor: false);
        $member->forceFill(['password' => 'correct-password'])->save();

        $this->actingAs($member)->withSession([TwoFactorSetupController::FRESH_LOGIN_KEY => time() - 1000])
            ->get(route('security.two-factor'))->assertOk()->assertSee('name="password"', false);

        $this->post(route('security.two-factor.start'), ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->assertNull($member->fresh()->two_factor_secret);

        $this->get(route('security.two-factor.scan'))->assertRedirect(route('security.two-factor'));

        $this->post(route('security.two-factor.start'), ['password' => 'correct-password'])->assertRedirect(route('security.two-factor.scan'));
        $this->assertNotNull($member->fresh()->two_factor_secret);
    }

    public function test_the_steps_cannot_be_skipped(): void
    {
        $member = $this->member(withTwoFactor: false);

        $this->freshlySignedIn($member);

        $this->get(route('security.two-factor.scan'))->assertRedirect(route('security.two-factor'));
        $this->get(route('security.two-factor.verify'))->assertRedirect(route('security.two-factor'));
        $this->post(route('security.two-factor.confirm'), ['code' => '123456'])->assertRedirect(route('security.two-factor'));
        $this->get(route('security.two-factor.recovery'))->assertRedirect(route('security.two-factor'));
        $this->get(route('security.two-factor.recovery.download'))->assertRedirect(route('security.two-factor'));
        $this->post(route('security.two-factor.finish'), ['saved' => '1'])->assertRedirect(route('security.two-factor'));

        $this->assertNull($member->fresh()->two_factor_confirmed_at);
    }

    public function test_a_wrong_code_gets_a_clear_message_and_does_not_confirm(): void
    {
        $member = $this->member(withTwoFactor: false);

        $this->freshlySignedIn($member)->post(route('security.two-factor.start'));

        $this->from(route('security.two-factor.verify'))->post(route('security.two-factor.confirm'), ['code' => 'abc'])
            ->assertSessionHasErrors(['code' => 'Enter the 6-digit code shown in your app.']);

        $wrong = $this->currentCode($member) === '000000' ? '000001' : '000000';

        $this->from(route('security.two-factor.verify'))->post(route('security.two-factor.confirm'), ['code' => $wrong])
            ->assertRedirect(route('security.two-factor.verify'))
            ->assertSessionHasErrors('code');

        $this->assertNull($member->fresh()->two_factor_confirmed_at);
        $this->assertSame(1, AuditLog::where('action', 'mfa_code_rejected')->where('user_id', $member->id)->count());
        $this->get('/member')->assertRedirect(route('security.two-factor'));
    }

    public function test_guessing_codes_is_limited(): void
    {
        $member = $this->member(withTwoFactor: false);

        $this->freshlySignedIn($member)->post(route('security.two-factor.start'));

        for ($try = 0; $try < 4; $try++) {
            $this->post(route('security.two-factor.confirm'), ['code' => '000000']);
        }

        $this->post(route('security.two-factor.confirm'), ['code' => '000000'])
            ->assertSessionHasErrors(['code' => 'Too many attempts. Wait a minute and try again.']);
    }

    public function test_a_member_finishes_the_setup_and_lands_in_the_member_area(): void
    {
        $member = $this->member(withTwoFactor: false);

        $this->freshlySignedIn($member)->post(route('security.two-factor.start'));

        $page = $this->get(route('security.two-factor.scan'))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString('<svg', $page->getContent());
        $this->assertStringContainsString(substr(decrypt($member->fresh()->two_factor_secret), 0, 4), $page->getContent());

        $this->post(route('security.two-factor.confirm'), ['code' => $this->currentCode($member)])
            ->assertRedirect(route('security.two-factor.recovery'));

        $this->assertTrue($member->fresh()->hasConfirmedTwoFactor());

        // Confirmed, but the rest stays closed until the codes are acknowledged.
        $this->get('/member')->assertRedirect(route('security.two-factor'));
        $this->get(route('security.two-factor'))->assertRedirect(route('security.two-factor.recovery'));

        $codes = $member->fresh()->recoveryCodes();
        $this->assertCount(8, $codes);

        $this->get(route('security.two-factor.recovery'))->assertOk()->assertSee($codes[0])->assertSee('I saved my recovery codes');

        $download = $this->get(route('security.two-factor.recovery.download'))->assertOk();
        $this->assertStringContainsString($codes[0], $download->getContent());
        $this->assertStringContainsString('attachment', $download->headers->get('Content-Disposition'));

        $this->post(route('security.two-factor.finish'), [])->assertSessionHasErrors('saved');
        $this->get('/member')->assertRedirect(route('security.two-factor'));

        $this->post(route('security.two-factor.finish'), ['saved' => '1'])->assertRedirect(route('member.dashboard'));
        $this->get('/member')->assertOk();

        // Not shown again.
        $this->get(route('security.two-factor.recovery'))->assertRedirect(route('security.two-factor'));
        $this->get(route('security.two-factor'))->assertRedirect(route('member.dashboard'));
    }

    public function test_staff_land_in_nova_and_user_security_opens_afterwards(): void
    {
        $staff = $this->staff(Role::MembershipCoordinator, withTwoFactor: false);

        $this->freshlySignedIn($staff)->post(route('security.two-factor.start'));
        $this->post(route('security.two-factor.confirm'), ['code' => $this->currentCode($staff)]);

        $this->post(route('security.two-factor.finish'), ['saved' => '1'])->assertRedirect(url('/nova'));

        $this->get('/nova/user-security')->assertOk();
    }

    public function test_audit_entries_hold_no_secrets(): void
    {
        $member = $this->member(withTwoFactor: false);

        $this->freshlySignedIn($member)->post(route('security.two-factor.start'));
        $this->post(route('security.two-factor.confirm'), ['code' => $this->currentCode($member)]);
        $this->post(route('security.two-factor.finish'), ['saved' => '1']);

        $actions = AuditLog::where('user_id', $member->id)->orderBy('id')->pluck('action')->all();
        foreach (['mfa_setup_started', 'mfa_enrolled', 'mfa_recovery_codes_saved'] as $action) {
            $this->assertContains($action, $actions);
        }

        $all = AuditLog::where('user_id', $member->id)->get()->toJson();
        $this->assertStringNotContainsString(decrypt($member->fresh()->two_factor_secret), $all);
        $this->assertStringNotContainsString($member->fresh()->recoveryCodes()[0], $all);
    }

    public function test_someone_with_two_factor_is_not_kept_in_the_setup(): void
    {
        $member = $this->member();

        $this->actingAs($member)->get('/member')->assertOk();
        $this->get(route('security.two-factor'))->assertRedirect(route('member.dashboard'));
        $this->post(route('security.two-factor.start'))->assertRedirect(route('member.dashboard'));
    }
}
