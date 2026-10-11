<?php

namespace App\Policies;

use App\Models\MemberProfile;
use App\Models\User;

class MemberProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('members.view')
            || $user->checkPermissionTo('own-profile.manage');
    }

    public function view(User $user, MemberProfile $profile): bool
    {
        return $user->checkPermissionTo('members.view')
            || ($this->owns($user, $profile) && $user->checkPermissionTo('own-profile.manage'));
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo('members.create');
    }

    public function update(User $user, MemberProfile $profile): bool
    {
        return $user->checkPermissionTo('members.update')
            || ($this->owns($user, $profile) && $user->checkPermissionTo('own-profile.manage'));
    }

    public function delete(User $user, MemberProfile $profile): bool
    {
        return $user->checkPermissionTo('members.delete');
    }

    public function restore(User $user, MemberProfile $profile): bool
    {
        return false;
    }

    public function forceDelete(User $user, MemberProfile $profile): bool
    {
        return false;
    }

    public function downloadCertificate(User $user, MemberProfile $profile): bool
    {
        return $user->checkPermissionTo('members.view')
            || ($this->owns($user, $profile) && $user->checkPermissionTo('own-certificate.download'));
    }

    /**
     * Actions on a profile (e.g. certificate download) do their own checks via canRun().
     */
    public function runAction(User $user, MemberProfile $profile): bool
    {
        return $this->view($user, $profile);
    }

    private function owns(User $user, MemberProfile $profile): bool
    {
        return $profile->user_id === $user->id;
    }
}
