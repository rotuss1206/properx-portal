<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Read-only and append-only: nobody edits or deletes audit history from the UI.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('audit-logs.view');
    }

    public function view(User $user, AuditLog $log): bool
    {
        return $user->checkPermissionTo('audit-logs.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AuditLog $log): bool
    {
        return false;
    }

    public function delete(User $user, AuditLog $log): bool
    {
        return false;
    }
}
