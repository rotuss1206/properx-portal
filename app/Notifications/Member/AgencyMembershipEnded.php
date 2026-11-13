<?php

namespace App\Notifications\Member;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To each person of an agency (not its principal, who gets MembershipExpired) when the agency's
 * membership expires or ends (H2-H3). Email and the bell; nothing about the agency's payment.
 */
class AgencyMembershipEnded extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

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
            ->subject('Your agency’s ProPerX Alliance membership has ended')
            ->greeting('Hello,')
            ->line("Your agency's ProPerX Alliance membership has ended, so your membership is inactive.")
            ->line('You can still sign in, but your certificate, the compliance library and documents are closed. If your agency renews, your access returns automatically.')
            ->line('Questions about it? Please ask your agency or contact ProPerX.')
            ->action('Open the member portal', route('member.dashboard'))
            ->salutation("Regards,\nProPerX Alliance");
    }
}
