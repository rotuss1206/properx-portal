<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * User accounts: staff accounts are managed only by the Executive Director,
 * member accounts by roles with members.* permissions (Spec 3.7).
 *
 * Class-level abilities (viewAny / create) are refined per Nova resource
 * via viewAnyStaff / createStaff / viewAnyMembers / createMembers.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->viewAnyStaff($user) || $this->viewAnyMembers($user);
    }

    public function viewAnyStaff(User $user): bool
    {
        return $user->checkPermissionTo('staff.manage');
    }

    public function viewAnyMembers(User $user): bool
    {
        return $user->checkPermissionTo('members.view');
    }

    public function view(User $user, User $target): bool
    {
        if ($user->is($target)) {
            return true;
        }

        return $target->isMember()
            ? $user->checkPermissionTo('members.view')
            : $user->checkPermissionTo('staff.manage');
    }

    public function create(User $user): bool
    {
        return $this->createStaff($user) || $this->createMembers($user);
    }

    public function createStaff(User $user): bool
    {
        return $user->checkPermissionTo('staff.manage');
    }

    public function createMembers(User $user): bool
    {
        return $user->checkPermissionTo('members.create');
    }

    public function update(User $user, User $target): bool
    {
        return $target->isMember()
            ? $user->checkPermissionTo('members.update')
            : $user->checkPermissionTo('staff.manage');
    }

    /**
     * Giving a staff account a role in Nova. The actor needs staff.manage; the role must be
     * one Nova may hand out (never the Executive Director role, which only the
     * app:assign-executive-director command assigns); an Executive Director's own role
     * cannot be changed or removed here.
     */
    public function assignRole(User $user, User $target, string $role): bool
    {
        $assignable = array_map(fn (Role $role) => $role->value, Role::assignableStaff());

        return $user->checkPermissionTo('staff.manage')
            && in_array($role, $assignable, true)
            && ! ($target->exists && $target->isExecutiveDirector());
    }

    /**
     * An Executive Director account is never deleted in Nova: revoke the role first with
     * app:assign-executive-director --revoke.
     */
    public function delete(User $user, User $target): bool
    {
        if ($user->is($target) || $target->isExecutiveDirector()) {
            return false;
        }

        return $target->isMember()
            ? $user->checkPermissionTo('members.delete')
            : $user->checkPermissionTo('staff.manage');
    }

    public function restore(User $user, User $target): bool
    {
        return false;
    }

    public function forceDelete(User $user, User $target): bool
    {
        return false;
    }
}
