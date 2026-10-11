<?php

namespace App\Policies;

use App\Models\LibraryDocument;
use App\Models\User;

class LibraryDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('library.view');
    }

    /**
     * Drafts are visible only to those who can edit the library.
     */
    public function view(User $user, LibraryDocument $document): bool
    {
        return $user->checkPermissionTo('library.view')
            && ($document->published || $user->checkPermissionTo('library.update'));
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo('library.create');
    }

    public function update(User $user, LibraryDocument $document): bool
    {
        return $user->checkPermissionTo('library.update');
    }

    public function delete(User $user, LibraryDocument $document): bool
    {
        return $user->checkPermissionTo('library.delete');
    }

    public function restore(User $user, LibraryDocument $document): bool
    {
        return false;
    }

    public function forceDelete(User $user, LibraryDocument $document): bool
    {
        return false;
    }
}
