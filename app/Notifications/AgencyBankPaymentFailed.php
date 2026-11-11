<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the principal when the bank (ACH) payment of an agency enrollment fails: the enrollment is
 * open again and a one-time link leads back to it. Sent at once rather than queued, like the
 * "continue" email, so the link's token never waits in the jobs table. No member data.
 */
class AgencyBankPaymentFailed extends Notification
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
        return (new MailMessage)
            ->subject('Your ProPerX Alliance agency enrollment needs another payment')
            ->greeting('Hello,')
            ->line("Your bank didn't complete the payment for your agency enrollment, so nothing was charged and your agency is not enrolled yet.")
            ->line('Your answers are saved. Use the button below to choose another bank account or a card.')
            ->action('Continue enrollment', $this->url)
            ->line('The link works once.')
            ->salutation("Regards,\nProPerX Alliance");
    }
}
