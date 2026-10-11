<?php

namespace App\Nova\Actions;

use App\Enums\VerificationStatus;
use App\Models\AuditLog;
use App\Models\LicenseDocument;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;

/**
 * Shared logic of the Verify / Reject actions. Authorization: LicenseDocumentPolicy::runAction.
 */
abstract class ReviewLicenseDocument extends Action
{
    abstract protected function status(): VerificationStatus;

    protected function auditAction(): string
    {
        return $this->status() === VerificationStatus::Approved ? 'document_verified' : 'document_rejected';
    }

    /**
     * @param  Collection<int, LicenseDocument>  $models
     */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        foreach ($models as $document) {
            $document->update([
                'verification_status' => $this->status(),
                'admin_notes' => $fields->get('admin_notes') ?: $document->admin_notes,
            ]);

            AuditLog::record($this->auditAction(), $document);
        }

        return Action::message(trans_choice(':count document marked as :status.|:count documents marked as :status.', $models->count(), [
            'status' => $this->status()->value,
        ]));
    }
}
