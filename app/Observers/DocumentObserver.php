<?php

namespace App\Observers;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Audits uploads and deletions of license, tax and library documents (Spec 8).
 */
class DocumentObserver
{
    public function created(Model $document): void
    {
        AuditLog::record('document_uploaded', $document);
    }

    public function deleted(Model $document): void
    {
        AuditLog::record('document_deleted', $document);
    }
}
