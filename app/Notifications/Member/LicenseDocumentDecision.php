<?php

namespace App\Notifications\Member;

use App\Enums\VerificationStatus;
use App\Member\MemberMenu;
use App\Member\Overview;
use App\Models\LicenseDocument;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Laravel\Nova\Nova;

/**
 * Email that staff approved or rejected a license document; LicenseDocumentReviewed is its notice
 * in the bell. Queued with ids only, so the reason stays encrypted until the mail is written. No
 * names, numbers or file names, and nothing about the document in the subject. The body of a
 * rejection carries staff's reason for the member, which is the one exception to links only.
 */
class LicenseDocumentDecision extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly int $documentId,
        public readonly VerificationStatus $status,
    ) {
        $this->afterCommit();
    }

    public static function for(LicenseDocument $document): self
    {
        return new self($document->getKey(), $document->verification_status);
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Not for a document deleted since, or decided differently since: that decision has its own email.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $this->document($notifiable)?->verification_status === $this->status;
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)->greeting('Hello,');

        if ($this->status === VerificationStatus::Approved) {
            $mail->subject('Your ProPerX Alliance document has been approved')
                ->line('ProPerX Alliance has reviewed your license document and approved it. No further action is needed.')
                ->action('View your document', $this->documentUrl($notifiable));
        } else {
            $mail->subject('Action needed: your ProPerX Alliance document was not approved')
                ->line('ProPerX Alliance has reviewed your license document and could not approve it.')
                ->line('Reason: '.$this->document($notifiable)?->rejection_reason)
                ->line('Please upload a new document so we can review it again.')
                ->action('Upload a new document', $this->uploadUrl($notifiable));
        }

        return $mail->salutation("Regards,\nProPerX Alliance Membership Team");
    }

    private function document(User $notifiable): ?LicenseDocument
    {
        return $notifiable->licenseDocuments()->find($this->documentId);
    }

    private function documentUrl(User $notifiable): string
    {
        if (! config('portal.member_area.enabled')) {
            return url(Nova::path()."/resources/license-documents/{$this->documentId}");
        }

        return MemberMenu::visible($notifiable, 'license-documents')
            ? route('member.license-documents', ['document' => $this->documentId])
            : route('member.dashboard');
    }

    private function uploadUrl(User $notifiable): string
    {
        if (! config('portal.member_area.enabled')) {
            return url(Nova::path().'/resources/license-documents');
        }

        return Overview::for($notifiable)->canUpload()
            ? route('member.license-documents.upload')
            : route('member.dashboard');
    }
}
