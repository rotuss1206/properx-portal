<?php

namespace App\Notifications\Member;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your membership has expired" (H2-H3): to an agency's principal or an individual member once
 * the grace period after a failed renewal is over, or when the subscription ends. Email and the
 * bell; the kind only. Paying later restores everything (the payment's webhook).
 */
class MembershipExpired extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly string $kind)
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
     * @return array{kind: string}
     */
    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $agency = $this->kind === RenewalReminder::AGENCY;

        return (new MailMessage)
            ->subject('Your membership has expired')
            ->greeting('Hello,')
            ->line($agency
                ? "Your agency's ProPerX Alliance membership has expired, so your people's memberships are inactive."
                : 'Your ProPerX Alliance membership has expired.')
            ->line($agency
                ? 'Everyone can still sign in, but certificates, the compliance library and documents are closed. Each person has been told.'
                : 'You can still sign in, but your certificate, the compliance library and documents are closed.')
            ->line('Renew to restore access: as soon as the payment goes through, everything opens again.')
            ->action('Renew my membership', route('member.billing'))
            ->salutation("Regards,\nProPerX Alliance");
    }
}
