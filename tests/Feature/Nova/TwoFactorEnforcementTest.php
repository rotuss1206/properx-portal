<?php

namespace Tests\Feature\Nova;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * MFA is mandatory for the three staff roles and optional for members (Spec §8).
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
        config(['portal.require_staff_two_factor' => false]);
        $this->app->detectEnvironment(fn () => 'local');

        $this->actingAs($this->staff(Role::ExecutiveDirector, withTwoFactor: false))
            ->get('/nova/dashboards/main')
            ->assertOk();
    }

    public function test_requirement_cannot_be_disabled_outside_local(): void
    {
        config(['portal.require_staff_two_factor' => false]);
        $this->app->detectEnvironment(fn () => 'production');

        $this->actingAs($this->staff(Role::ExecutiveDirector, withTwoFactor: false))
            ->getJson('/nova-api/member-profiles')
            ->assertForbidden();
    }

    public function test_two_factor_is_optional_for_members(): void
    {
        $member = $this->member();

        $this->assertNull($member->two_factor_confirmed_at);

        $this->actingAs($member)
            ->get('/nova/dashboards/my-membership')
            ->assertOk();

        $this->getJson('/nova-api/member-profiles')->assertOk();
    }

    public function test_staff_without_two_factor_cannot_download_certificates(): void
    {
        $profile = $this->member()->memberProfile;

        $this->actingAs($this->staff(Role::ExecutiveDirector, withTwoFactor: false))
            ->get(route('certificates.download', $profile))
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
