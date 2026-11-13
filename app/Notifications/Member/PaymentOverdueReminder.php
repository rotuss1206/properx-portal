<?php

namespace App\Notifications\Member;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * While a renewal payment is overdue (H2; days left in portal.renewals.grace_reminder_days): to the
 * agency's principal or the individual member, by email and in the bell. The access end date and
 * the kind only; the payment link is the member portal's Billing page (Stripe's invoice there).
 */
class PaymentOverdueReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly string $kind, public readonly string $accessEndsOn)
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
     * @return array{kind: string, access_ends_on: string}
     */
    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, 'access_ends_on' => $this->accessEndsOn];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $date = CarbonImmutable::parse($this->accessEndsOn)->format('F j, Y');
        $agency = $this->kind === RenewalReminder::AGENCY;

        return (new MailMessage)
            ->subject('Reminder: your ProPerX Alliance renewal payment is overdue')
            ->greeting('Hello,')
            ->line($agency
                ? "We could not collect your agency's renewal payment. Everyone keeps access until {$date}."
                : "We could not collect your renewal payment. You keep access until {$date}.")
            ->line($agency
                ? 'Please pay before then, or the membership expires and your people lose access to their certificates, the library and documents.'
                : 'Please pay before then, or your membership expires and your certificate, the library and documents close.')
            ->action('Pay now', route('member.billing'))
            ->salutation("Regards,\nProPerX Alliance");
    }
}
