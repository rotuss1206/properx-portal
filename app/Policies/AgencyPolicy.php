<?php

namespace App\Policies;

use App\Models\Agency;
use App\Models\User;

/**
 * Staff (Executive Director, Membership Coordinator) work with every agency; an Agency
 * Principal sees and manages only the agency they lead.
 */
class AgencyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('agencies.view');
    }

    public function view(User $user, Agency $agency): bool
    {
        return $user->checkPermissionTo('agencies.view')
            || ($agency->isLedBy($user) && $user->checkPermissionTo('own-agency.view'));
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo('agencies.create');
    }

    public function update(User $user, Agency $agency): bool
    {
        return $user->checkPermissionTo('agencies.update')
            || ($agency->isLedBy($user) && $user->checkPermissionTo('own-agency.manage'));
    }

    public function delete(User $user, Agency $agency): bool
    {
        return $user->checkPermissionTo('agencies.delete');
    }

    public function restore(User $user, Agency $agency): bool
    {
        return false;
    }

    public function forceDelete(User $user, Agency $agency): bool
    {
        return false;
    }

    /**
     * Staff only, never self-serve.
     */
    public function reassignPrincipal(User $user, Agency $agency): bool
    {
        return $user->checkPermissionTo('agencies.update');
    }

    /**
     * Roster: each person's type, invite status and license status (never health plan data).
     */
    public function viewRoster(User $user, Agency $agency): bool
    {
        return $this->view($user, $agency);
    }

    public function manageRoster(User $user, Agency $agency): bool
    {
        return $this->update($user, $agency);
    }
}
