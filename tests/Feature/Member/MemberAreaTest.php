<?php

namespace Tests\Feature\Member;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Nova\Http\Middleware\HandleInertiaRequests;
use Laravel\Nova\Nova;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * The member area skeleton (docs/specs/member-area.md, stage 1): who gets in, the
 * redirects between Nova and /member in both states of MEMBER_AREA_ENABLED, the menu and
 * the Renewal due card.
 */
class MemberAreaTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        config(['portal.member_area.enabled' => true]);
    }

    // Switched off

    public function test_while_off_the_area_does_not_exist_and_members_keep_using_nova(): void
    {
        config(['portal.member_area.enabled' => false]);
        $member = $this->member();

        $this->get('/member')->assertNotFound();
        $this->actingAs($member)->get('/member')->assertNotFound();
        $this->get('/member/profile')->assertNotFound();
        $this->get('/nova/dashboards/my-membership')->assertOk();
    }

    // Access

    public function test_members_get_in(): void
    {
        $this->actingAs($this->member())->get('/member')->assertOk()->assertSee('Good ', false);
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get('/member')->assertRedirect();
        $this->assertGuest();
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function staffRoles(): array
    {
        return array_combine(
            array_map(fn (Role $role) => $role->value, Role::staff()),
            array_map(fn (Role $role) => [$role], Role::staff()),
        );
    }

    #[DataProvider('staffRoles')]
    public function test_staff_are_sent_to_nova_which_they_use_as_before(Role $role): void
    {
        $staff = $this->staff($role);

        $this->actingAs($staff)->get('/member')->assertRedirect('/nova');
        $this->actingAs($staff)->get('/member/billing')->assertRedirect('/nova');
        $this->actingAs($staff)->get('/nova/dashboards/main')->assertOk();
    }

    public function test_accounts_without_the_member_role_are_refused(): void
    {
        $this->actingAs(User::factory()->withTwoFactor()->create())->get('/member')->assertForbidden();
    }

    public function test_two_factor_setup_comes_first_as_in_nova(): void
    {
        $this->actingAs($this->member(withTwoFactor: false))
            ->get('/member')
            ->assertRedirect(route('nova.pages.user-security'));
    }

    // Redirects from Nova

    public function test_members_opening_nova_land_in_the_member_area(): void
    {
        $member = $this->member();

        $this->actingAs($member)->get('/nova/dashboards/my-membership')->assertRedirect(route('member.dashboard'));
        $this->get('/nova/resources/license-documents')->assertRedirect(route('member.dashboard'));

        // Inside the Nova app (Inertia visit): a full page load to /member.
        $version = app(HandleInertiaRequests::class)->version(request());
        $this->get('/nova/resources/tax-documents', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', route('member.dashboard'));
    }

    public function test_security_and_signing_out_stay_in_nova(): void
    {
        $member = $this->member();

        $this->actingAs($member)->get('/nova/user-security')->assertOk();
        $this->getJson('/nova-api/license-documents')->assertOk(); // Nova's API is not redirected (stage 8)

        $this->post('/nova/logout')->assertRedirect();
        $this->assertGuest();
    }

    public function test_after_sign_in_members_land_in_the_member_area_and_staff_in_nova(): void
    {
        // Nova's login (and 2FA challenge) responses send everyone to Nova::initialPathUrl().
        $member = $this->member();
        $this->actingAs($member)->get(Nova::initialPathUrl(request()))->assertRedirect(route('member.dashboard'));

        $director = $this->staff(Role::ExecutiveDirector);
        $this->actingAs($director)->get(Nova::initialPathUrl(request()))->assertOk();
    }

    // Menu, header and the Renewal due card

    public function test_the_menu_lists_the_member_pages_with_the_current_one_marked(): void
    {
        $member = $this->member();

        $response = $this->actingAs($member)->get('/member/certificate')->assertOk();

        $response->assertSeeInOrder(['Dashboard', 'Profile', 'License documents', 'Tax documents', 'Certificate', 'Billing', 'Compliance library', 'Notifications']);
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('member.certificate'), '#').'"\s+class="ppx-sidebar__item is-active"\s+aria-current="page"#', $response->getContent());
        $this->assertSame(1, substr_count($response->getContent(), 'aria-current="page"'));
        $response->assertSee('#'.$member->memberProfile->member_number);
        $response->assertSee('Coming soon');
        $response->assertSee('ppx-header__search', false); // member_area.header_search
        $response->assertSee('action="'.route('nova.logout').'"', false);
        $response->assertSee('href="'.route('nova.pages.user-security').'"', false);

        foreach (['profile', 'license-documents', 'tax-documents', 'billing', 'library', 'notifications'] as $page) {
            $this->get("/member/{$page}")->assertOk();
        }
    }

    public function test_agency_people_see_no_license_uploads_and_only_the_principal_sees_billing(): void
    {
        $agency = $this->agency(2);
        $principal = $agency->principal()->first();
        $person = $agency->members()->where('user_id', '!=', $principal->id)->first()->user;

        $this->actingAs($person)->get('/member')->assertOk()->assertDontSee('License documents')->assertDontSee('Billing');
        $this->get('/member/billing')->assertNotFound();
        $this->get('/member/license-documents')->assertNotFound();

        $this->actingAs($principal)->get('/member')->assertOk()->assertSee('Billing')->assertDontSee('License documents');
        $this->get('/member/billing')->assertOk();

        config(['portal.member_area.agency_people.principal_billing' => false]);
        $this->get('/member/billing')->assertNotFound();
    }

    public function test_the_renewal_card_shows_within_90_days(): void
    {
        config(['portal.payments.payment_url' => 'https://pay.example.test/portal']);
        $member = $this->member();

        $member->memberProfile->forceFill(['paid_through_at' => today()->addDays(76)->endOfDay()])->save();
        $this->actingAs($member)->get('/member')
            ->assertSee('Membership renews in 76 days.')
            ->assertSee('href="https://pay.example.test/portal"', false);

        $member->memberProfile->forceFill(['paid_through_at' => today()->addDays(120)])->save();
        $this->get('/member')->assertDontSee('Renewal due');
    }

    public function test_pages_run_under_the_strict_csp(): void
    {
        $response = $this->actingAs($this->member())->get('/member')->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('unsafe-eval', $csp);
        preg_match("/'nonce-([^']+)'/", $csp, $nonce);

        $response->assertSee('portal/member.js?v=', false);
        $response->assertSee('nonce="'.$nonce[1].'"', false);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bnonce=)[^>]*>/', $response->getContent());
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+="/', $response->getContent()); // no inline handlers
    }
}
