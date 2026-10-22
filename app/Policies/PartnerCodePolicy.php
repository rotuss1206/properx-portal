<?php

namespace App\Policies;

use App\Models\PartnerCode;
use App\Models\User;

class PartnerCodePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('partner-codes.view');
    }

    public function view(User $user, PartnerCode $partnerCode): bool
    {
        return $user->checkPermissionTo('partner-codes.view');
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo('partner-codes.manage');
    }

    public function update(User $user, PartnerCode $partnerCode): bool
    {
        return $user->checkPermissionTo('partner-codes.manage');
    }

    /**
     * A code that agencies enrolled with is kept for the record; deactivate it instead.
     */
    public function delete(User $user, PartnerCode $partnerCode): bool
    {
        $used = $partnerCode->agencies_count ?? $partnerCode->agencies()->count();

        return $user->checkPermissionTo('partner-codes.manage') && $used === 0;
    }

    public function restore(User $user, PartnerCode $partnerCode): bool
    {
        return false;
    }

    public function forceDelete(User $user, PartnerCode $partnerCode): bool
    {
        return false;
    }
}
