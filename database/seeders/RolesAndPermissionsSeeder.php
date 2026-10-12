<?php

namespace Database\Seeders;

use App\Enums\Role as RoleName;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles and permissions from Spec 3.7. Safe to re-run.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Permissions granted to each role. Executive Director gets every permission.
     *
     * @var array<string, list<string>>
     */
    private const ROLE_PERMISSIONS = [
        RoleName::MembershipCoordinator->value => [
            'members.view',
            'members.create',
            'members.update',
            'license-documents.view',
            'license-documents.verify',
            'members.message',
            'reminder-logs.view',
        ],
        RoleName::ComplianceSpecialist->value => [
            'members.view',
            'library.view',
            'library.create',
            'library.update',
            'library.delete',
            'tax-documents.view',
            'tax-documents.upload',
            'tax-documents.delete',
        ],
        RoleName::Member->value => [
            'own-profile.manage',
            'own-license-documents.manage',
            'own-tax-documents.view',
            'own-certificate.download',
            'own-notification-settings.manage',
            'library.view',
        ],
        RoleName::ApiService->value => [
            'webhooks.stripe.handle',
            'members.payment-status.update',
            'reminders.dispatch',
        ],
    ];

    /**
     * Permissions held only by the Executive Director.
     */
    private const EXECUTIVE_ONLY = [
        'staff.manage',
        'members.delete',
        'certificates.sign',
        'audit-logs.view',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect(self::ROLE_PERMISSIONS)
            ->flatten()
            ->merge(self::EXECUTIVE_ONLY)
            ->unique()
            ->values();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        // Model events (which normally reset the cache) are muted while seeding.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::ROLE_PERMISSIONS as $role => $rolePermissions) {
            Role::findOrCreate($role)->syncPermissions($rolePermissions);
        }

        // Full portal capabilities, excluding machine-only API permissions.
        $apiOnly = self::ROLE_PERMISSIONS[RoleName::ApiService->value];

        Role::findOrCreate(RoleName::ExecutiveDirector->value)
            ->syncPermissions($permissions->diff($apiOnly)->values());

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
