<?php

namespace App\Notifications\Member;

use App\Enums\VerificationStatus;
use App\Models\LicenseDocument;
use Illuminate\Notifications\Notification;

/**
 * In-app notice (member area) that staff approved or rejected a license document. Ids and
 * enum values only; App\Member\NotificationFeed writes the text when it is shown.
 */
class LicenseDocumentReviewed extends Notification
{
    public function __construct(
        public readonly int $documentId,
        public readonly VerificationStatus $status,
    ) {}

    public static function for(LicenseDocument $document): self
    {
        return new self($document->getKey(), $document->verification_status);
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{document_id: int, status: string}
     */
    public function toArray(object $notifiable): array
    {
        return ['document_id' => $this->documentId, 'status' => $this->status->value];
    }
}
