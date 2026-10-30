<?php

namespace App\Observers;

use App\Enums\VerificationStatus;
use App\Models\AuditLog;
use App\Models\LicenseDocument;
use App\Models\TaxDocument;
use App\Notifications\Member\LicenseDocumentReviewed;
use App\Notifications\Member\TaxDocumentAvailable;
use Illuminate\Database\Eloquent\Model;

/**
 * Audits uploads and deletions of license, tax and library documents (Spec 8), and tells the
 * member in the member area when staff add a tax document or review a license document.
 */
class DocumentObserver
{
    public function created(Model $document): void
    {
        AuditLog::record('document_uploaded', $document);

        if ($document instanceof TaxDocument) {
            $document->user?->notify(new TaxDocumentAvailable($document->getKey()));
        }
    }

    public function updated(Model $document): void
    {
        if ($document instanceof LicenseDocument
            && $document->wasChanged('verification_status')
            && in_array($document->verification_status, [VerificationStatus::Approved, VerificationStatus::Rejected], true)) {
            $document->user?->notify(LicenseDocumentReviewed::for($document));
        }
    }

    public function deleted(Model $document): void
    {
        AuditLog::record('document_deleted', $document);
    }
}
