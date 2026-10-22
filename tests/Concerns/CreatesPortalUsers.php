<?php

namespace Tests\Concerns;

use App\Enums\Role;
use App\Models\Agency;
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

    /**
     * Member with confirmed 2FA (mandatory for every Nova user) unless $withTwoFactor is false.
     */
    protected function member(string $licenseState = 'active', bool $withTwoFactor = true): User
    {
        $factory = $withTwoFactor ? User::factory()->withTwoFactor() : User::factory();
        $user = $factory->create()->assignRole(Role::Member->value);

        MemberProfile::factory()->{$licenseState}()->for($user)->create();

        return $user->load('memberProfile');
    }

    /**
     * Agency of $people members; the first one is the principal.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function agency(int $people = 2, array $attributes = []): Agency
    {
        $agency = Agency::factory()->create($attributes);
        $agency->update(['principal_user_id' => $this->member()->id]);

        for ($person = 2; $person <= $people; $person++) {
            $this->member()->memberProfile->update(['agency_id' => $agency->id]);
        }

        return $agency->refresh();
    }

    protected function apiService(): User
    {
        return User::factory()->create()->assignRole(Role::ApiService->value);
    }
}
