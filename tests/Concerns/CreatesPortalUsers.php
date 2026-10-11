<?php

namespace Tests\Concerns;

use App\Enums\Role;
use App\Models\MemberProfile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

trait CreatesPortalUsers
{
    protected function seedRoles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * Staff user with confirmed 2FA unless $withTwoFactor is false.
     */
    protected function staff(Role $role, bool $withTwoFactor = true): User
    {
        $factory = User::factory();

        if ($withTwoFactor) {
            $factory = $factory->withTwoFactor();
        }

        return $factory->create()->assignRole($role->value);
    }

    protected function member(string $licenseState = 'active'): User
    {
        $user = User::factory()->create()->assignRole(Role::Member->value);

        MemberProfile::factory()->{$licenseState}()->for($user)->create();

        return $user->load('memberProfile');
    }

    protected function apiService(): User
    {
        return User::factory()->create()->assignRole(Role::ApiService->value);
    }
}
