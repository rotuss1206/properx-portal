<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To an agency's principal when its membership payment fails (Agency spec 6, 9): a link to pay
 * (Stripe's page of the invoice, or the payment portal). Sent at once rather than queued so the
 * invoice link never waits in the jobs table. No names, amounts or agency details.
 */
class AgencyPaymentFailed extends Notification
{
    public function __construct(public readonly ?string $payUrl) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Action needed: your agency's ProPerX Alliance membership payment")
            ->greeting('Hello,')
            ->line("The payment for your agency's ProPerX Alliance membership did not go through.")
            ->line("Everyone on your agency's membership keeps their access for now. Please pay the invoice, or update the payment method, so it stays that way.");

        return ($this->payUrl !== null ? $mail->action('Pay now', $this->payUrl) : $mail->line('Sign in to the member portal to see how to pay.'))
            ->salutation("Regards,\nProPerX Alliance");
    }
}
