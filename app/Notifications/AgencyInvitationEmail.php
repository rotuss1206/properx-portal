<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The invitation of a person on an enrolled agency, and its reminders (Agency spec 7). Sent at
 * once (notifyNow) by App\Services\Agencies\AgencyInvitations, never queued: the token exists
 * only in this email. No names, agency details or other member data. Wording: docs/agency-emails.md.
 */
class AgencyInvitationEmail extends Notification
{
    public function __construct(
        private readonly string $token,
        private readonly CarbonInterface $expiresAt,
        private readonly bool $reminder = false,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->reminder
                ? 'Reminder: set up your ProPerX Alliance member account'
                : 'Your agency has enrolled you in ProPerX Alliance')
            ->greeting('Hello,');

        if ($this->reminder) {
            $message->line('Your agency enrolled you as a member of ProPerX Alliance, and your member account is still waiting to be set up.');
        } else {
            $message
                ->line('Your agency has enrolled you as a member of ProPerX Alliance, and your membership is paid for.')
                ->line('Set up your account in the member portal: choose a password, turn on two-factor sign-in with an authenticator app, then check your profile and accept the terms.');
        }

        return $message
            ->action('Set up my account', route('join.invitation', $this->token))
            ->line('This link works once and expires on '.$this->expiresAt->timezone(config('portal.schedule_timezone'))->format('F j, Y').'. If it has expired, open it anyway and ask for a new invitation.')
            ->line('If you were not expecting this email, you can ignore it.')
            ->salutation("Regards,\nProPerX Alliance");
    }
}
