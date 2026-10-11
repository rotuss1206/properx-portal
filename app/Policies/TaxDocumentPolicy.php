<?php

namespace App\Policies;

use App\Models\TaxDocument;
use App\Models\User;

class TaxDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('tax-documents.view')
            || $user->checkPermissionTo('own-tax-documents.view');
    }

    public function view(User $user, TaxDocument $document): bool
    {
        return $user->checkPermissionTo('tax-documents.view')
            || ($document->user_id === $user->id && $user->checkPermissionTo('own-tax-documents.view'));
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo('tax-documents.upload');
    }

    public function update(User $user, TaxDocument $document): bool
    {
        return $user->checkPermissionTo('tax-documents.upload');
    }

    public function delete(User $user, TaxDocument $document): bool
    {
        return $user->checkPermissionTo('tax-documents.delete');
    }

    public function restore(User $user, TaxDocument $document): bool
    {
        return false;
    }

    public function forceDelete(User $user, TaxDocument $document): bool
    {
        return false;
    }
}
