<?php

namespace Tests\Feature\Nova;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * The Nova sidebar built by App\Nova\Menu\SidebarMenu: items per role, icons and the
 * active item. resources/js/nova/sidebar.js only draws this menu.
 */
class SidebarMenuTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function menu(User $user, string $path): array
    {
        return $this->actingAs($user)->get($path)->assertOk()->viewData('page')['props']['novaConfig']['mainMenu'];
    }

    /**
     * Section name => item names (active items marked with "*").
     *
     * @param  array<int, array<string, mixed>>  $menu
     * @return array<string, list<string>>
     */
    private function outline(array $menu): array
    {
        return collect($menu)->mapWithKeys(fn (array $section) => [
            $section['name'] => collect($section['items'])->map(fn (array $item) => ($item['active'] ? '*' : '').$item['name'])->all(),
        ])->all();
    }

    public function test_members_get_their_items_in_the_design_order(): void
    {
        $member = $this->member();

        $menu = $this->menu($member, '/nova/dashboards/my-membership');

        $this->assertSame(['Menu' => [
            '*Dashboard', 'Profile', 'License documents', 'Tax documents', 'Certificate', 'Compliance library', 'Notifications',
        ]], $this->outline($menu));

        $this->assertSame(
            ['dashboard', 'profile', 'license', 'tax', 'certificate', 'library', 'notifications'],
            array_column($menu[0]['items'], 'portalIcon'),
        );

        $profile = '/nova/resources/member-profiles/'.$member->memberProfile->id;
        $paths = array_column($menu[0]['items'], 'path', 'name');
        $this->assertSame($profile, $paths['Profile']);
        $this->assertSame($profile.'?section=certificate', $paths['Certificate']);
        $this->assertSame($profile.'/edit?section=notifications', $paths['Notifications']);
    }

    public function test_billing_appears_only_once_its_page_is_configured(): void
    {
        config(['portal.menu.billing_path' => '/billing']);

        $items = $this->outline($this->menu($this->member(), '/nova/dashboards/my-membership'))['Menu'];

        $this->assertSame(['*Dashboard', 'Profile', 'License documents', 'Tax documents', 'Certificate', 'Billing', 'Compliance library', 'Notifications'], $items);
    }

    public function test_the_item_the_member_came_from_is_the_active_one(): void
    {
        $member = $this->member();
        $profile = '/nova/resources/member-profiles/'.$member->memberProfile->id;

        $active = fn (string $path) => collect($this->menu($member, $path)[0]['items'])->where('active', true)->pluck('name')->all();

        $this->assertSame(['Profile'], $active($profile));
        $this->assertSame(['Profile'], $active($profile.'/edit'));
        $this->assertSame(['Certificate'], $active($profile.'?section=certificate'));
        $this->assertSame(['Notifications'], $active($profile.'/edit?section=notifications'));
        $this->assertSame(['License documents'], $active('/nova/resources/license-documents'));
    }

    /**
     * @return array<string, array{Role, array<string, list<string>>}>
     */
    public static function staffMenus(): array
    {
        return [
            'Executive Director' => [Role::ExecutiveDirector, [
                'Menu' => ['*Dashboard'],
                'Members' => ['Member Accounts', 'Member Profiles'],
                'Documents' => ['License Documents', 'Tax Documents', 'Library'],
                'Agencies' => ['Agencies', 'Partner Codes'],
                'Logs' => ['Audit Log', 'Reminder Log'],
                'Administration' => ['Staff'],
            ]],
            'Membership Coordinator' => [Role::MembershipCoordinator, [
                'Menu' => ['*Dashboard'],
                'Members' => ['Member Accounts', 'Member Profiles'],
                'Documents' => ['License Documents'],
                'Agencies' => ['Agencies', 'Partner Codes'],
                'Logs' => ['Reminder Log'],
            ]],
            'Compliance Specialist' => [Role::ComplianceSpecialist, [
                'Menu' => ['*Dashboard'],
                'Members' => ['Member Accounts', 'Member Profiles'],
                'Documents' => ['Tax Documents', 'Library'],
            ]],
        ];
    }

    /**
     * @param  array<string, list<string>>  $expected
     */
    #[DataProvider('staffMenus')]
    public function test_staff_get_their_groups_as_before(Role $role, array $expected): void
    {
        $menu = $this->menu($this->staff($role), '/nova/dashboards/main');

        $this->assertSame($expected, $this->outline($menu));

        foreach ($menu as $section) {
            foreach ($section['items'] as $item) {
                $this->assertNotEmpty($item['portalIcon'], "{$item['name']} has an icon");
            }
        }
    }

    public function test_the_sidebar_assets_are_served_to_signed_in_users(): void
    {
        $response = $this->actingAs($this->member())->get('/nova/dashboards/my-membership')->assertOk();

        $scripts = collect($response->viewData('page')['props']['novaConfig'] ?? [])->toArray();

        $this->assertArrayHasKey('portalSidebar', $scripts);
        $response->assertSee('portal-sidebar', false);
    }
}
