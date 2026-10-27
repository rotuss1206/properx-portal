<?php

namespace Tests\Feature;

use App\Enums\Role as RoleName;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\ReferenceData;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The seed_reference_data migration: the KEENEY pipeline runs only `migrate --force`.
 */
class ReferenceDataMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_27_100000_seed_reference_data.php');
    }

    /**
     * @return array{roles: array<string, list<string>>, permissions: list<string>, service_accounts: list<string>}
     */
    private function snapshot(): array
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [
            'roles' => Role::query()->with('permissions')->orderBy('name')->get()
                ->mapWithKeys(fn (Role $role) => [$role->name => $role->permissions->pluck('name')->sort()->values()->all()])
                ->all(),
            'permissions' => Permission::query()->orderBy('name')->pluck('name')->all(),
            'service_accounts' => User::role(RoleName::ApiService->value)->pluck('email')->all(),
        ];
    }

    /**
     * An empty reference data state, as on a database the placeholder skeleton migrated.
     */
    private function wipeReferenceData(): void
    {
        User::query()->where('email', ReferenceData::SERVICE_ACCOUNT_EMAIL)->delete();
        DB::table('role_has_permissions')->delete();
        DB::table('model_has_roles')->delete();
        DB::table('model_has_permissions')->delete();
        DB::table('roles')->delete();
        DB::table('permissions')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_fresh_database_gets_every_role_permission_and_the_service_account(): void
    {
        $snapshot = $this->snapshot();

        foreach (ReferenceData::ROLE_PERMISSIONS as $role => $permissions) {
            $this->assertEqualsCanonicalizing($permissions, $snapshot['roles'][$role], $role);
        }

        $machineOnly = ReferenceData::ROLE_PERMISSIONS[RoleName::ApiService->value];
        $this->assertEqualsCanonicalizing(array_values(array_diff($snapshot['permissions'], $machineOnly)), $snapshot['roles'][RoleName::ExecutiveDirector->value]);
        $this->assertSame([ReferenceData::SERVICE_ACCOUNT_EMAIL], $snapshot['service_accounts']);
        $this->assertCount(count(RoleName::cases()), $snapshot['roles']);
    }

    public function test_empty_and_partly_seeded_databases_end_up_identical(): void
    {
        $expected = $this->snapshot();

        // Empty reference data.
        $this->wipeReferenceData();
        $this->migration()->up();
        $this->assertSame($expected, $this->snapshot());

        // Roles already partly created (the roles seeder was run before on production).
        $this->wipeReferenceData();
        Role::findOrCreate(RoleName::Member->value)->givePermissionTo(Permission::findOrCreate('own-profile.manage'));
        Role::findOrCreate(RoleName::ExecutiveDirector->value)->givePermissionTo(Permission::findOrCreate('staff.manage'));
        $member = User::factory()->create()->assignRole(RoleName::Member->value);

        $this->migration()->up();

        $this->assertSame($expected, $this->snapshot());
        $this->assertTrue($member->fresh()->hasRole(RoleName::Member->value));
    }

    public function test_never_removes_grants_or_roles(): void
    {
        Role::findByName(RoleName::ExecutiveDirector->value)->givePermissionTo(Permission::findOrCreate('custom.manual-grant'));
        $custom = Role::findOrCreate('Custom Role');

        $this->migration()->up();

        $this->assertTrue(Role::findByName(RoleName::ExecutiveDirector->value)->hasPermissionTo('custom.manual-grant'));
        $this->assertModelExists($custom);
    }

    public function test_running_twice_changes_nothing_and_is_not_audited(): void
    {
        $this->wipeReferenceData();
        $auditBefore = AuditLog::query()->count();

        $this->migration()->up();
        $first = $this->snapshot();
        $this->migration()->up();

        $this->assertSame($first, $this->snapshot());
        $this->assertSame(1, User::role(RoleName::ApiService->value)->count());
        $this->assertSame($auditBefore, AuditLog::query()->count());
    }

    public function test_keeps_an_existing_service_account(): void
    {
        $service = User::role(RoleName::ApiService->value)->sole();
        $password = $service->password;

        $this->migration()->up();

        $this->assertSame($password, $service->fresh()->password);
        $this->assertSame([$service->id], User::role(RoleName::ApiService->value)->pluck('id')->all());
    }

    public function test_rollback_deletes_nothing(): void
    {
        $before = $this->snapshot();

        $this->migration()->down();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_seeders_use_the_same_reference_data(): void
    {
        $expected = $this->snapshot();
        $this->wipeReferenceData();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($expected, $this->snapshot());
    }
}
