<?php

namespace App\Notifications;

use App\Models\Agency;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the principal when ProPerX cancels the agency's membership ("Cancel agency membership"):
 * at once, or at the end of the paid period. Queued with the agency id only; no names or amounts,
 * the end date the only figure.
 */
class AgencyMembershipCanceled extends Notification implements ShouldQueue
{
    use Queueable;

    public const NOW = 'now';

    public const PERIOD_END = 'period_end';

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly int $agencyId, public readonly string $timing)
    {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $endsOn = Agency::query()->find($this->agencyId)?->paid_through_at?->format('F j, Y');
        $mail = (new MailMessage)
            ->subject('Your agency’s ProPerX Alliance membership has been canceled')
            ->greeting('Hello,');

        if ($this->timing === self::PERIOD_END && $endsOn !== null) {
            $mail->line("ProPerX Alliance has canceled your agency's membership. It stays active until {$endsOn}, and it will not renew.")
                ->line('Until then your people keep their access to the member portal.');
        } else {
            $mail->line("ProPerX Alliance has canceled your agency's membership as of today.")
                ->line('Your people can still sign in to the member portal, but their certificates, the library and documents are closed.');
        }

        return $mail
            ->line('If you have questions or this is unexpected, please contact ProPerX.')
            ->action('Open the member portal', route('member.dashboard'))
            ->salutation("Regards,\nProPerX Alliance");
    }
}
