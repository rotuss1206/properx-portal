<?php

namespace App\Services;

use App\Enums\Role as RoleName;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reference data every environment needs (Spec 3.7): roles, their permissions and the API
 * Service system account. The single source for both the seed_reference_data migration
 * (the KEENEY pipeline only runs `migrate --force`) and the seeders.
 *
 * Additive and idempotent: it creates what is missing and grants what a role lacks, but never
 * deletes a role, a permission or a grant, so it is safe on a database where the roles were
 * already (partly) created. New roles or permissions: add them here AND ship a new migration
 * that calls seed() again (docs/DEVELOPMENT.md).
 */
class ReferenceData
{
    /**
     * Permissions granted to each role. The Executive Director gets every permission
     * except the machine-only ones of the API Service account.
     *
     * @var array<string, list<string>>
     */
    public const ROLE_PERMISSIONS = [
        RoleName::MembershipCoordinator->value => [
            'members.view',
            'members.create',
            'members.update',
            'license-documents.view',
            'license-documents.verify',
            'members.message',
            'reminder-logs.view',
            'agencies.view',
            'agencies.create',
            'agencies.update',
            'partner-codes.view',
            'partner-codes.manage',
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
        // Layered on Member: only the agency the user leads (checked in AgencyPolicy).
        RoleName::AgencyPrincipal->value => [
            'own-agency.view',
            'own-agency.manage',
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
    public const EXECUTIVE_ONLY = [
        'staff.manage',
        'members.delete',
        'certificates.sign',
        'audit-logs.view',
        'agencies.delete',
        // Change tier / Change rate type actions; normally both follow from headcount and payment date.
        'agencies.pricing.override',
    ];

    public const SERVICE_ACCOUNT_EMAIL = 'api-service@properx.test';

    public function seed(): void
    {
        $this->quietly(function () {
            $this->seedRolesAndPermissions();
            $this->ensureServiceAccount();
        });
    }

    public function seedRolesAndPermissions(): void
    {
        $this->quietly(function () {
            $registrar = app(PermissionRegistrar::class);
            $registrar->forgetCachedPermissions();

            $permissions = collect(self::ROLE_PERMISSIONS)->flatten()->merge(self::EXECUTIVE_ONLY)->unique()->values();

            foreach ($permissions as $permission) {
                Permission::findOrCreate($permission);
            }

            // findOrCreate reads the (stale) cache, so reset it before assigning.
            $registrar->forgetCachedPermissions();

            foreach (self::ROLE_PERMISSIONS as $role => $rolePermissions) {
                // givePermissionTo() adds the missing grants and keeps every existing one.
                Role::findOrCreate($role)->givePermissionTo($rolePermissions);
            }

            $machineOnly = self::ROLE_PERMISSIONS[RoleName::ApiService->value];
            Role::findOrCreate(RoleName::ExecutiveDirector->value)->givePermissionTo($permissions->diff($machineOnly)->values()->all());

            $registrar->forgetCachedPermissions();
        });
    }

    /**
     * The API Service account (no web login, a random password nobody knows). An existing
     * account with the role is kept; no factory, so it also works without dev dependencies.
     */
    public function ensureServiceAccount(): User
    {
        return $this->quietly(function () {
            $existing = app(ServiceAccount::class)->user();

            if ($existing !== null) {
                return $existing;
            }

            $user = User::query()->firstOrNew(['email' => self::SERVICE_ACCOUNT_EMAIL]);
            $user->forceFill([
                'name' => 'API Service',
                'password' => $user->exists ? $user->password : Str::random(64),
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            $user->assignRole(RoleName::ApiService->value);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $user;
        });
    }

    /**
     * Reference data is not an audited action: no model events and no spatie role events
     * (they would write audit entries with no actor, e.g. while migrating).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function quietly(callable $callback): mixed
    {
        $eventsEnabled = config('permission.events_enabled');
        config(['permission.events_enabled' => false]);

        try {
            return Model::withoutEvents($callback);
        } finally {
            config(['permission.events_enabled' => $eventsEnabled]);
        }
    }
}
