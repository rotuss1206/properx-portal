<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the principal when a bank (ACH) payment clears after the enrollment page was left on
 * "processing" and Stripe's webhook enrolled the agency. No names, amounts or agency details.
 */
class AgencyEnrollmentCompleted extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your ProPerX Alliance agency enrollment is complete')
            ->greeting('Hello,')
            ->line('Your bank payment has cleared and your agency is now enrolled in ProPerX Alliance.')
            ->line('Everyone on your list will receive an email invitation to set up their member account.')
            ->salutation("Regards,\nProPerX Alliance");
    }
}
