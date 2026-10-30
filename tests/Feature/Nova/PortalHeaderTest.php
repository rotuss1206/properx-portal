<?php

namespace Tests\Feature\Nova;

use App\Enums\Role;
use App\Support\PortalHeader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * The shared header of Nova (resources/js/nova/header.js) and the member area
 * (components/member/header.blade.php): same data, same menu, search only for staff in Nova.
 */
class PortalHeaderTest extends TestCase
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
        return array_combine(
            array_map(fn (Role $role) => $role->value, Role::staff()),
            array_map(fn (Role $role) => [$role], Role::staff()),
        );
    }

    #[DataProvider('staffRoles')]
    public function test_staff_get_nova_search_and_their_own_account_as_profile(Role $role): void
    {
        $staff = $this->staff($role);

        $header = $this->actingAs($staff)->get('/nova/dashboards/main')->assertOk()
            ->assertSee('portal-header', false)        // Nova::script / Nova::style
            ->assertSee('portal/header.css?v=', false) // shared styles
            ->viewData('page')['props']['novaConfig']['portalHeader'];

        $this->assertTrue($header['search']);
        $this->assertSame('/nova/resources/staff/'.$staff->id, parse_url($header['profileUrl'], PHP_URL_PATH));
        $this->assertSame(route('nova.pages.user-security'), $header['securityUrl']);
        $this->assertSame(now(config('portal.schedule_timezone'))->format('l, j F Y'), $header['date']);

        // The Profile entry opens: every staff member may view their own account.
        $this->getJson('/nova-api/staff/'.$staff->id)->assertOk();
    }

    public function test_members_in_nova_get_no_search_and_their_profile(): void
    {
        $member = $this->member();

        $header = $this->actingAs($member)->get('/nova/dashboards/my-membership')->assertOk()
            ->viewData('page')['props']['novaConfig']['portalHeader'];

        $this->assertFalse($header['search']);
        $this->assertSame('/nova/resources/member-profiles/'.$member->memberProfile->id, parse_url($header['profileUrl'], PHP_URL_PATH));
        $this->assertSame(PortalHeader::for($member, 'member')['initials'], $header['initials']);
    }

    public function test_the_member_area_header_has_the_same_parts(): void
    {
        config(['portal.member_area.enabled' => true]);
        $member = $this->member();
        $header = PortalHeader::for($member, 'member');

        $this->assertFalse($header['search']);
        $this->assertSame(route('member.profile'), $header['profileUrl']);

        $this->actingAs($member)->get('/member')->assertOk()
            ->assertSee('portal/header.css?v=', false)
            ->assertSee($header['date'])
            ->assertSee('ppx-header__glyph--bell', false)
            ->assertSee('ppx-header__glyph--menu', false)
            ->assertSeeInOrder(['Profile', 'Security', 'Sign out'])
            ->assertDontSee('ppx-header__search', false);

        config(['portal.member_area.header_search' => true]);
        $this->assertTrue(PortalHeader::for($member, 'member')['search']);
    }

    public function test_the_header_script_keeps_novas_header_as_the_fallback(): void
    {
        $js = file_get_contents(resource_path('js/nova/header.js'));

        foreach (['GlobalSearch', 'UserMenu', 'ThemeDropdown', 'errorCaptured', 'shell(false)'] as $part) {
            $this->assertStringContainsString($part, $js);
        }

        $this->assertStringNotContainsString('template:', $js);
        $this->assertStringNotContainsString('innerHTML', $js);
    }
}
