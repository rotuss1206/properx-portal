<?php

namespace App\Notifications\Member;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To an individual member ProPerX staff moved into an agency: their membership now comes through
 * the agency; an individual subscription bought on properx.org is not canceled by the portal, and
 * nothing is refunded. Email and the bell; no names or amounts.
 */
class MovedToAgency extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct()
    {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * @return array<string, never>
     */
    public function toArray(object $notifiable): array
    {
        return [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your ProPerX Alliance membership is now through your agency')
            ->greeting('Hello,')
            ->line('ProPerX has moved your membership to your agency. From now on your agency pays for it, and you keep your account, documents and settings.')
            ->line('If you paid for an individual membership on properx.org, it is not canceled automatically and nothing is refunded. ProPerX will help you cancel it; questions are welcome.')
            ->action('Open the member portal', route('member.dashboard'))
            ->salutation("Regards,\nProPerX Alliance");
    }
}
