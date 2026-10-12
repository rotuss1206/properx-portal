<?php

namespace App\Policies;

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
     * The Executive Director account can only be revoked directly in the database.
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
