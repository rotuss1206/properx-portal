<?php

namespace App\Notifications\Member;

use Illuminate\Notifications\Notification;

/**
 * In-app notice (member area) that staff uploaded a tax document for the member. Ids only;
 * App\Member\NotificationFeed writes the text when it is shown.
 */
class TaxDocumentAvailable extends Notification
{
    public function __construct(public readonly int $documentId) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{document_id: int}
     */
    public function toArray(object $notifiable): array
    {
        return ['document_id' => $this->documentId];
    }
}
