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
    public function test_staff_without_two_factor_is_sent_to_user_security(Role $role): void
    {
        $this->actingAs($this->staff($role, withTwoFactor: false));

        $this->get('/nova/dashboards/main')->assertRedirect(route('nova.pages.user-security'));
        $this->getJson('/nova-api/member-profiles')->assertForbidden();
        $this->get('/nova/user-security')->assertOk();
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

        $this->get('/nova/dashboards/my-membership')->assertRedirect(route('nova.pages.user-security'));
        $this->getJson('/nova-api/member-profiles')->assertForbidden();
        $this->get(CertificateGeneratorService::downloadUrl($member->memberProfile))
            ->assertRedirect(route('nova.pages.user-security'));
        $this->get('/nova/user-security')->assertOk();
    }

    public function test_members_with_two_factor_get_in(): void
    {
        $this->actingAs($this->member())
            ->get('/nova/dashboards/my-membership')
            ->assertOk();

        $this->getJson('/nova-api/member-profiles')->assertOk();
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
            ->assertRedirect(route('nova.pages.user-security'));
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
