<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Password;

/**
 * Sent to a member created from a paid application, only with INTAKE_SEND_INVITE: they set their
 * password through Nova's password reset page. No member data in the body.
 */
class MemberInvited extends Notification implements ShouldQueue
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
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('nova.pages.password.reset', ['token' => Password::broker()->createToken($notifiable)]);
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Your ProPerX Alliance member portal account')
            ->greeting('Hello,')
            ->line('Your account in the ProPerX Alliance member portal is ready.')
            ->line('Choose your password to sign in. You will then set up two-factor authentication.')
            ->action('Set your password', $url)
            ->line("The link works once and expires in {$minutes} minutes. After that, use \"Forgot your password?\" on the sign-in page.")
            ->salutation("Regards,\nProPerX Alliance");
    }
}
