<?php

namespace App\Policies;

use App\Models\ReminderLog;
use App\Models\User;

/**
 * Read-only: entries are written by the reminder job only.
 */
class ReminderLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('reminder-logs.view');
    }

    public function view(User $user, ReminderLog $log): bool
    {
        return $user->checkPermissionTo('reminder-logs.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ReminderLog $log): bool
    {
        return false;
    }

    public function delete(User $user, ReminderLog $log): bool
    {
        return false;
    }
}
