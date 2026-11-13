<?php

namespace App\Notifications\Member;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To a person their agency's principal removes (G4): their membership through the agency ends on
 * the renewal date, with a link to apply as an individual member. Email and the bell.
 */
class AgencyRemovalScheduled extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $endsOn)
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
     * @return array{ends_on: string}
     */
    public function toArray(object $notifiable): array
    {
        return ['ends_on' => $this->endsOn];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $date = CarbonImmutable::parse($this->endsOn)->format('F j, Y');
        $mail = (new MailMessage)
            ->subject('Your ProPerX Alliance membership through your agency is ending')
            ->greeting('Hello,')
            ->line("Your agency has removed you from its ProPerX Alliance membership. Your membership through the agency ends on {$date}; until then everything stays as it is.")
            ->line('To stay a member after that, you can apply for an individual membership.');

        $url = config('portal.registration.individual_url');

        return ($url ? $mail->action('Apply as an individual member', (string) $url) : $mail)
            ->salutation("Regards,\nProPerX Alliance");
    }
}
