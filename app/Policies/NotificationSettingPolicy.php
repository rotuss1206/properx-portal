<?php

namespace App\Policies;

use App\Models\NotificationSetting;
use App\Models\User;

/**
 * Settings are edited from the member profile, see MemberProfile Nova resource.
 */
class NotificationSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('members.view')
            || $user->checkPermissionTo('own-notification-settings.manage');
    }

    public function view(User $user, NotificationSetting $setting): bool
    {
        return $user->checkPermissionTo('members.view') || $this->ownsAndManages($user, $setting);
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo('members.update')
            || $user->checkPermissionTo('own-notification-settings.manage');
    }

    public function update(User $user, NotificationSetting $setting): bool
    {
        return $user->checkPermissionTo('members.update') || $this->ownsAndManages($user, $setting);
    }

    public function delete(User $user, NotificationSetting $setting): bool
    {
        return false;
    }

    /**
     * Whether the user may change notification settings belonging to $owner.
     */
    public function manageFor(User $user, User $owner): bool
    {
        return $user->checkPermissionTo('members.update')
            || ($user->is($owner) && $user->checkPermissionTo('own-notification-settings.manage'));
    }

    private function ownsAndManages(User $user, NotificationSetting $setting): bool
    {
        return $setting->user_id === $user->id && $user->checkPermissionTo('own-notification-settings.manage');
    }
}
