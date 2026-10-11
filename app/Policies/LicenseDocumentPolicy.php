<?php

namespace App\Policies;

use App\Enums\VerificationStatus;
use App\Models\LicenseDocument;
use App\Models\User;

class LicenseDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('license-documents.view')
            || ($user->checkPermissionTo('own-license-documents.manage') && ! $user->hasRestrictedMembership());
    }

    public function view(User $user, LicenseDocument $document): bool
    {
        return $user->checkPermissionTo('license-documents.view') || $this->ownsAndManages($user, $document);
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo('license-documents.verify')
            || ($user->checkPermissionTo('own-license-documents.manage') && ! $user->hasRestrictedMembership());
    }

    /**
     * Editing (verification status, admin notes) is a staff task.
     */
    public function update(User $user, LicenseDocument $document): bool
    {
        return $user->checkPermissionTo('license-documents.verify');
    }

    /**
     * Members may withdraw their own document while it is still pending review.
     */
    public function delete(User $user, LicenseDocument $document): bool
    {
        return $user->checkPermissionTo('license-documents.verify')
            || ($this->ownsAndManages($user, $document) && $document->verification_status === VerificationStatus::Pending);
    }

    public function restore(User $user, LicenseDocument $document): bool
    {
        return false;
    }

    public function forceDelete(User $user, LicenseDocument $document): bool
    {
        return false;
    }

    public function runAction(User $user, LicenseDocument $document): bool
    {
        return $user->checkPermissionTo('license-documents.verify');
    }

    /**
     * Members with a canceled / lapsed membership lose access to license documents (Spec §7).
     */
    private function ownsAndManages(User $user, LicenseDocument $document): bool
    {
        return $document->user_id === $user->id
            && $user->checkPermissionTo('own-license-documents.manage')
            && ! $user->hasRestrictedMembership();
    }
}
