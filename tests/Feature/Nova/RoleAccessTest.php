<?php

namespace Tests\Feature\Nova;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * Which Nova resources and dashboards each role may open (ТЗ, розділ 3.7).
 */
class RoleAccessTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    private const RESOURCES = [
        'staff', 'members', 'member-profiles', 'license-documents',
        'tax-documents', 'library-documents', 'reminder-logs', 'audit-logs',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    /**
     * @return array<string, array{Role, list<string>}>
     */
    public static function allowedResources(): array
    {
        return [
            'Executive Director' => [Role::ExecutiveDirector, self::RESOURCES],
            'Membership Coordinator' => [Role::MembershipCoordinator, [
                'members', 'member-profiles', 'license-documents', 'reminder-logs',
            ]],
            'Compliance Specialist' => [Role::ComplianceSpecialist, [
                'members', 'member-profiles', 'tax-documents', 'library-documents',
            ]],
            'Member' => [Role::Member, [
                'member-profiles', 'license-documents', 'tax-documents', 'library-documents',
            ]],
        ];
    }

    /**
     * @param  list<string>  $allowed
     */
    #[DataProvider('allowedResources')]
    public function test_role_sees_only_its_resources(Role $role, array $allowed): void
    {
        $user = $role === Role::Member ? $this->member() : $this->staff($role);

        foreach (self::RESOURCES as $resource) {
            $expected = in_array($resource, $allowed, true) ? 200 : 403;

            $this->actingAs($user)
                ->getJson("/nova-api/{$resource}")
                ->assertStatus($expected, "{$role->value} → {$resource}");
        }
    }

    #[DataProvider('allowedResources')]
    public function test_main_menu_lists_only_allowed_resources(Role $role, array $allowed): void
    {
        $user = $role === Role::Member ? $this->member() : $this->staff($role);

        $menu = $this->actingAs($user)
            ->get($role === Role::Member ? '/nova/dashboards/my-membership' : '/nova/dashboards/main')
            ->assertOk()
            ->viewData('page')['props']['novaConfig']['mainMenu'];

        $json = json_encode($menu, JSON_UNESCAPED_SLASHES);
        $this->assertIsString($json, json_last_error_msg());
        preg_match_all('#"path":"/nova/resources/([a-z-]+)"#', $json, $matches);

        $resources = array_values(array_unique($matches[1]));
        sort($resources);
        sort($allowed);

        $this->assertSame($allowed, $resources);
    }

    public function test_api_service_account_cannot_enter_nova(): void
    {
        $this->actingAs($this->apiService())
            ->get('/nova/dashboards/main')
            ->assertForbidden();

        $this->getJson('/nova-api/member-profiles')->assertForbidden();
    }

    public function test_view_nova_gate_is_enforced_in_local_environment(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        $this->actingAs($this->apiService())
            ->getJson('/nova-api/library-documents')
            ->assertForbidden();
    }

    public function test_user_without_role_cannot_enter_nova(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/nova-api/library-documents')
            ->assertForbidden();
    }

    public function test_staff_dashboard_is_hidden_from_members(): void
    {
        $this->actingAs($this->member())
            ->getJson('/nova-api/dashboards/main')
            ->assertNotFound();

        $this->getJson('/nova-api/dashboards/cards/main/metrics/expiring-licenses')->assertNotFound();
        $this->getJson('/nova-api/dashboards/cards/main')->assertNotFound();
    }

    public function test_member_dashboard_shows_own_license(): void
    {
        $member = $this->member('expiring');

        $this->actingAs($member)
            ->getJson('/nova-api/dashboards/cards/my-membership/metrics/my-license')
            ->assertOk()
            ->assertJsonPath('value.0.title', 'Status: Expiring');
    }

    public function test_staff_dashboard_metrics_work(): void
    {
        $this->member('active');
        $this->member('expiring');
        $this->member('expired');

        $this->actingAs($this->staff(Role::MembershipCoordinator));

        $this->getJson('/nova-api/dashboards/cards/main/metrics/expiring-licenses')
            ->assertOk()
            ->assertJsonPath('value.value', 1);

        $this->getJson('/nova-api/dashboards/cards/main/metrics/license-status-partition')
            ->assertOk()
            ->assertJsonCount(3, 'value.value');

        $this->getJson('/nova-api/dashboards/cards/main/metrics/new-members?range=30')->assertOk();

        $this->getJson('/nova-api/dashboards/cards/main/metrics/upcoming-expirations')
            ->assertOk()
            ->assertJsonCount(2, 'value');
    }
}
