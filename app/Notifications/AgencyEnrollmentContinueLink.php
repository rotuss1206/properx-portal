<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Save and continue later" of the agency enrollment (Agency spec 5.6): a one-time link back
 * into the saved draft. Sent at once rather than queued, so the link's token never waits in the
 * jobs table. No names, agency or answers in it: only the link.
 */
class AgencyEnrollmentContinueLink extends Notification
{
    public function __construct(public readonly string $url) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $days = (int) config('portal.registration.draft_days');

        return (new MailMessage)
            ->subject('Continue your ProPerX Alliance agency enrollment')
            ->greeting('Hello,')
            ->line('Your agency enrollment is saved. Use the button below to pick up where you left off.')
            ->action('Continue enrollment', $this->url)
            ->line("The link works once. Your answers are kept for {$days} days after your last change; to get a new link, choose \"Save and continue later\" again.")
            ->line("If you didn't start an agency enrollment with ProPerX Alliance, you can ignore this email.")
            ->salutation("Regards,\nProPerX Alliance");
    }
}
