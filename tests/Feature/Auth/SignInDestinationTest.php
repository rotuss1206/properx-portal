<?php

namespace Tests\Feature\Auth;

use App\Enums\PersonType;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * Where people land after the password and the two-factor code, with MEMBER_AREA_ENABLED on and off,
 * whatever they had opened before signing in. No combination may end in a 404.
 */
class SignInDestinationTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    private const MEMBER_HOME_ON = '/member';

    private const MEMBER_HOME_OFF = '/nova/dashboards/my-membership';

    private const STAFF_HOME = '/nova/dashboards/main';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    /**
     * @return array<string, array{bool, string}>
     */
    public static function everyone(): array
    {
        $cases = [];

        foreach ([true => 'on', false => 'off'] as $enabled => $state) {
            foreach (['member', 'principal', 'employee', 'director', 'coordinator', 'compliance'] as $kind) {
                $cases["{$kind}, member area {$state}"] = [(bool) $enabled, $kind];
            }
        }

        return $cases;
    }

    #[DataProvider('everyone')]
    public function test_no_intended_address_leads_home(bool $enabled, string $kind): void
    {
        $this->assertLandsOn($this->home($enabled, $kind), $enabled, $kind, null);
    }

    #[DataProvider('everyone')]
    public function test_the_address_of_the_bug_report_leads_home(bool $enabled, string $kind): void
    {
        $this->assertLandsOn($this->home($enabled, $kind), $enabled, $kind, self::STAFF_HOME);
    }

    #[DataProvider('everyone')]
    public function test_the_members_nova_dashboard_leads_home(bool $enabled, string $kind): void
    {
        $this->assertLandsOn($this->home($enabled, $kind), $enabled, $kind, self::MEMBER_HOME_OFF);
    }

    #[DataProvider('everyone')]
    public function test_novas_root_leads_home(bool $enabled, string $kind): void
    {
        $this->assertLandsOn($this->home($enabled, $kind), $enabled, $kind, '/nova');
    }

    #[DataProvider('everyone')]
    public function test_a_member_area_page_opens_only_for_those_who_use_it(bool $enabled, string $kind): void
    {
        $expected = $enabled && ! $this->isStaff($kind) ? '/member/profile' : $this->home($enabled, $kind);

        $this->assertLandsOn($expected, $enabled, $kind, '/member/profile');
    }

    #[DataProvider('everyone')]
    public function test_a_nova_page_opens_except_for_those_who_use_the_member_area(bool $enabled, string $kind): void
    {
        $expected = $enabled && ! $this->isStaff($kind) ? self::MEMBER_HOME_ON : '/nova/user-security';

        $this->assertLandsOn($expected, $enabled, $kind, '/nova/user-security');
    }

    #[DataProvider('everyone')]
    public function test_a_resource_that_does_not_exist_leads_home(bool $enabled, string $kind): void
    {
        $this->assertLandsOn($this->home($enabled, $kind), $enabled, $kind, '/nova/resources/nonsense');
    }

    #[DataProvider('everyone')]
    public function test_novas_api_is_no_place_to_land(bool $enabled, string $kind): void
    {
        $this->assertLandsOn($this->home($enabled, $kind), $enabled, $kind, '/nova-api/scripts/portal-sidebar');
    }

    public function test_a_resource_they_may_not_list_leads_home(): void
    {
        $this->assertLandsOn(self::MEMBER_HOME_OFF, false, 'member', '/nova/resources/audit-logs');
        $this->assertLandsOn(self::STAFF_HOME, false, 'coordinator', '/nova/resources/library-documents');
    }

    public function test_a_resource_they_may_list_opens(): void
    {
        $this->assertLandsOn('/nova/resources/member-profiles', false, 'member', '/nova/resources/member-profiles');
        $this->assertLandsOn('/nova/resources/audit-logs', true, 'director', '/nova/resources/audit-logs');
    }

    public function test_a_member_section_closed_to_agency_people_leads_home(): void
    {
        $this->assertLandsOn('/member/license-documents', true, 'member', '/member/license-documents');
        $this->assertLandsOn(self::MEMBER_HOME_ON, true, 'principal', '/member/license-documents');
        $this->assertLandsOn(self::MEMBER_HOME_ON, true, 'employee', '/member/license-documents');
    }

    public function test_the_unsubscribe_link_survives_signing_in(): void
    {
        $this->assertLandsOn('/member/profile', true, 'member', '/member/profile?section=notifications');
    }

    /**
     * Signing in without a code (no two-factor yet) goes through the same response into the wizard,
     * and the wizard's last step leads home.
     */
    #[DataProvider('everyone')]
    public function test_the_two_factor_wizard_leads_home(bool $enabled, string $kind): void
    {
        config(['portal.member_area.enabled' => $enabled]);
        $user = $this->person($kind, withTwoFactor: false);

        $this->withSession(['url.intended' => url(self::STAFF_HOME)]);
        $signedIn = $this->postJson('/nova/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->assertSame('/security/setup-2fa', $this->follow($signedIn->json('redirect'))['url']);

        $this->post(route('security.two-factor.start'))->assertRedirect(route('security.two-factor.scan'));
        $this->post(route('security.two-factor.confirm'), ['code' => $this->code($user)])->assertRedirect(route('security.two-factor.recovery'));
        $finished = $this->post(route('security.two-factor.finish'), ['saved' => '1'])->assertRedirect();

        $landed = $this->follow($finished->headers->get('Location'));
        $this->assertSame($this->home($enabled, $kind), $landed['url']);
        $this->assertSame(200, $landed['status']);
    }

    #[DataProvider('everyone')]
    public function test_signed_in_people_are_also_led_off_a_dashboard_that_is_not_theirs(bool $enabled, string $kind): void
    {
        config(['portal.member_area.enabled' => $enabled]);
        $this->actingAs($this->person($kind));

        foreach ([self::STAFF_HOME, self::MEMBER_HOME_OFF, '/nova/dashboards/nonsense'] as $dashboard) {
            $landed = $this->follow(url($dashboard));

            $this->assertSame($this->home($enabled, $kind), $landed['url']);
            $this->assertSame(200, $landed['status']);
        }
    }

    public function test_an_inertia_visit_to_a_dashboard_that_is_not_theirs_leaves_with_a_full_load(): void
    {
        config(['portal.member_area.enabled' => false]);
        $this->actingAs($this->member());

        $version = $this->get(self::MEMBER_HOME_OFF)->assertOk()->viewData('page')['version'];

        $this->get(self::STAFF_HOME, ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', self::MEMBER_HOME_OFF);
    }

    private function assertLandsOn(string $expected, bool $enabled, string $kind, ?string $intended): void
    {
        config(['portal.member_area.enabled' => $enabled]);
        $user = $this->person($kind);

        if ($intended !== null) {
            $this->withSession(['url.intended' => url($intended)]);
        }

        $this->postJson('/nova/login', ['email' => $user->email, 'password' => 'password'])->assertOk()->assertJson(['two_factor' => true]);
        $response = $this->postJson('/nova/user-security/two-factor-challenge', ['code' => $this->code($user)])->assertOk();

        $landed = $this->follow($response->json('redirect'));

        $this->assertSame($expected, $landed['url']);
        $this->assertSame(200, $landed['status']);
        $this->assertNull(session('url.intended'));

        $this->post('/nova/logout');
    }

    /**
     * Follows redirects by hand, as the browser would, and fails on any 404 along the way.
     *
     * @return array{url: string, status: int}
     */
    private function follow(string $url): array
    {
        for ($hop = 0; $hop < 6; $hop++) {
            $response = $this->get($url);
            $this->assertNotSame(404, $response->getStatusCode(), "404 at {$url}");

            if (! $response->isRedirect()) {
                return ['url' => (string) parse_url($url, PHP_URL_PATH), 'status' => $response->getStatusCode()];
            }

            $url = $this->location($response);
        }

        $this->fail("Too many redirects, last {$url}");
    }

    private function location(TestResponse $response): string
    {
        return (string) $response->headers->get('Location');
    }

    private function home(bool $enabled, string $kind): string
    {
        return match (true) {
            $this->isStaff($kind) => self::STAFF_HOME,
            $enabled => self::MEMBER_HOME_ON,
            default => self::MEMBER_HOME_OFF,
        };
    }

    private function isStaff(string $kind): bool
    {
        return in_array($kind, ['director', 'coordinator', 'compliance'], true);
    }

    private function person(string $kind, bool $withTwoFactor = true): User
    {
        return match ($kind) {
            'member' => $this->member(withTwoFactor: $withTwoFactor),
            'principal' => $this->principal($withTwoFactor),
            'employee' => $this->agencyEmployee($withTwoFactor),
            'director' => $this->staff(Role::ExecutiveDirector, $withTwoFactor),
            'coordinator' => $this->staff(Role::MembershipCoordinator, $withTwoFactor),
            'compliance' => $this->staff(Role::ComplianceSpecialist, $withTwoFactor),
        };
    }

    private function principal(bool $withTwoFactor): User
    {
        $principal = $this->agency(2)->principal()->first();

        return $withTwoFactor ? $principal : $this->withoutTwoFactor($principal);
    }

    private function agencyEmployee(bool $withTwoFactor): User
    {
        $employee = $this->member(withTwoFactor: $withTwoFactor);
        $employee->memberProfile->forceFill(['agency_id' => $this->agency(2)->id, 'person_type' => PersonType::AgencyEmployee])->save();

        return $employee->fresh();
    }

    private function withoutTwoFactor(User $user): User
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();

        return $user;
    }

    private function code(User $user): string
    {
        return app(Google2FA::class)->getCurrentOtp(decrypt($user->fresh()->two_factor_secret));
    }
}
