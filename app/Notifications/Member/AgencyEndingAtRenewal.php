<?php

namespace App\Notifications\Member;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To everyone staying on an agency once removals leave it with fewer than two people (G4): the
 * agency's membership will not renew; individual membership is offered. No credits. Email and the bell.
 */
class AgencyEndingAtRenewal extends Notification implements ShouldQueue
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
            ->subject('Your agency’s ProPerX Alliance membership will not renew')
            ->greeting('Hello,')
            ->line("An agency membership needs at least two people. After the changes on your agency's list, your agency's membership ends on {$date} and will not renew.")
            ->line('Until then everything stays as it is. To stay a member after that, you can apply for an individual membership.');

        $url = config('portal.registration.individual_url');

        return ($url ? $mail->action('Apply as an individual member', (string) $url) : $mail)
            ->salutation("Regards,\nProPerX Alliance");
    }
}
