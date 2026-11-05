<?php

namespace Tests\Feature\Nova;

use App\Enums\Role;
use App\Services\Certificates\CertificateGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * MFA is mandatory for every Nova user, staff and members (KEENEY platform rule, Spec 8).
 */
class TwoFactorEnforcementTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    private const DISABLE_URL = '/nova/user-security/two-factor-authentication';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function staffRoles(): array
    {
        return [
            'Executive Director' => [Role::ExecutiveDirector],
            'Membership Coordinator' => [Role::MembershipCoordinator],
            'Compliance Specialist' => [Role::ComplianceSpecialist],
        ];
    }

    #[DataProvider('staffRoles')]
    public function test_staff_without_two_factor_is_sent_to_the_setup(Role $role): void
    {
        $this->actingAs($this->staff($role, withTwoFactor: false));

        $this->get('/nova/dashboards/main')->assertRedirect(route('security.two-factor'));
        $this->getJson('/nova-api/member-profiles')->assertForbidden();
        $this->get('/nova/user-security')->assertRedirect(route('security.two-factor'));
    }

    #[DataProvider('staffRoles')]
    public function test_staff_with_two_factor_gets_in(Role $role): void
    {
        $this->actingAs($this->staff($role))
            ->get('/nova/dashboards/main')
            ->assertOk();
    }

    public function test_requirement_can_be_disabled_in_local_environment(): void
    {
        config(['portal.require_two_factor' => false]);
        $this->app->detectEnvironment(fn () => 'local');

        $this->actingAs($this->staff(Role::ExecutiveDirector, withTwoFactor: false))
            ->get('/nova/dashboards/main')
            ->assertOk();
    }

    public function test_requirement_cannot_be_disabled_outside_local(): void
    {
        config(['portal.require_two_factor' => false]);
        $this->app->detectEnvironment(fn () => 'production');

        $this->actingAs($this->staff(Role::ExecutiveDirector, withTwoFactor: false))
            ->getJson('/nova-api/member-profiles')
            ->assertForbidden();
    }

    public function test_members_without_two_factor_are_sent_to_user_security(): void
    {
        $member = $this->member(withTwoFactor: false);

        $this->actingAs($member);

        $this->get('/nova/dashboards/my-membership')->assertRedirect(route('security.two-factor'));
        $this->getJson('/nova-api/member-profiles')->assertForbidden();
        $this->get(CertificateGeneratorService::downloadUrl($member->memberProfile))
            ->assertRedirect(route('security.two-factor'));
        $this->get('/nova/user-security')->assertRedirect(route('security.two-factor'));
    }

    public function test_members_with_two_factor_get_in(): void
    {
        $this->actingAs($this->member())
            ->get('/nova/dashboards/my-membership')
            ->assertOk();

        $this->getJson('/nova-api/member-profiles')->assertOk();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function people(): array
    {
        return ['a member' => ['member'], 'staff' => ['staff']];
    }

    #[DataProvider('people')]
    public function test_two_factor_cannot_be_turned_off_while_it_is_required(string $who): void
    {
        $user = $who === 'member' ? $this->member() : $this->staff(Role::ExecutiveDirector);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->delete(self::DISABLE_URL)
            ->assertForbidden();

        $this->deleteJson(self::DISABLE_URL)->assertForbidden();

        $this->assertTrue($user->fresh()->hasConfirmedTwoFactor());
        $this->assertNotNull($user->fresh()->two_factor_recovery_codes);
    }

    public function test_two_factor_stays_on_outside_local_even_with_the_flag_off(): void
    {
        config(['portal.require_two_factor' => false]);
        $this->app->detectEnvironment(fn () => 'production');
        $member = $this->member();

        $this->actingAs($member)
            ->withSession(['auth.password_confirmed_at' => time(), '_token' => 'csrf'])
            ->withHeader('X-CSRF-TOKEN', 'csrf')
            ->deleteJson(self::DISABLE_URL)
            ->assertForbidden();

        $this->assertTrue($member->fresh()->hasConfirmedTwoFactor());
    }

    public function test_locally_with_the_flag_off_two_factor_can_be_turned_off(): void
    {
        config(['portal.require_two_factor' => false]);
        $this->app->detectEnvironment(fn () => 'local');
        $staff = $this->staff(Role::ExecutiveDirector);

        $this->actingAs($staff)
            ->withSession(['auth.password_confirmed_at' => time(), '_token' => 'csrf'])
            ->withHeader('X-CSRF-TOKEN', 'csrf')
            ->deleteJson(self::DISABLE_URL)
            ->assertOk();

        $this->assertFalse($staff->fresh()->hasConfirmedTwoFactor());
    }

    #[DataProvider('people')]
    public function test_a_confirmed_secret_cannot_be_replaced_while_two_factor_is_required(string $who): void
    {
        $user = $who === 'member' ? $this->member() : $this->staff(Role::ExecutiveDirector);
        $secret = $user->two_factor_secret;
        $codes = $user->two_factor_recovery_codes;

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(self::DISABLE_URL, ['force' => 1])
            ->assertForbidden();

        $this->assertSame($secret, $user->fresh()->two_factor_secret);
        $this->assertSame($codes, $user->fresh()->two_factor_recovery_codes);

        // Without force nothing changes either, and nothing is refused.
        $this->postJson(self::DISABLE_URL)->assertOk();
        $this->assertSame($secret, $user->fresh()->two_factor_secret);
    }

    public function test_locally_with_the_flag_off_force_works_as_in_fortify(): void
    {
        config(['portal.require_two_factor' => false]);
        $this->app->detectEnvironment(fn () => 'local');
        $staff = $this->staff(Role::ExecutiveDirector);
        $secret = $staff->two_factor_secret;

        $this->actingAs($staff)
            ->withSession(['auth.password_confirmed_at' => time(), '_token' => 'csrf'])
            ->withHeader('X-CSRF-TOKEN', 'csrf')
            ->postJson(self::DISABLE_URL, ['force' => 1])
            ->assertOk();

        $this->assertNotSame($secret, $staff->fresh()->two_factor_secret);
    }

    public function test_the_user_security_page_is_told_to_hide_disable_only_while_required(): void
    {
        $staff = $this->staff(Role::ExecutiveDirector);

        $header = fn () => $this->actingAs($staff)->get('/nova/user-security')->assertOk()
            ->viewData('page')['props']['novaConfig']['portalHeader'];

        $this->assertTrue($header()['twoFactorLocked']);

        config(['portal.require_two_factor' => false]);
        $this->app->detectEnvironment(fn () => 'local');

        $this->assertFalse($header()['twoFactorLocked']);
    }

    public function test_api_service_account_is_not_asked_for_two_factor(): void
    {
        // It never reaches Nova (viewNova gate); the rule targets people who use the portal.
        $this->assertFalse($this->apiService()->requiresTwoFactor());
    }

    public function test_staff_without_two_factor_cannot_download_certificates(): void
    {
        $profile = $this->member()->memberProfile;

        $this->actingAs($this->staff(Role::ExecutiveDirector, withTwoFactor: false))
            ->get(CertificateGeneratorService::downloadUrl($profile))
            ->assertRedirect(route('security.two-factor'));
    }

    public function test_login_redirects_to_two_factor_challenge_when_enabled(): void
    {
        $director = $this->staff(Role::ExecutiveDirector);

        // Nova's login form posts JSON and then opens the challenge page itself.
        $this->postJson('/nova/login', ['email' => $director->email, 'password' => 'password'])
            ->assertOk()
            ->assertJson(['two_factor' => true]);

        $this->assertGuest();
    }
}
