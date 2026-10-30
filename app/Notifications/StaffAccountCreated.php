<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Password;

/**
 * Sent to a new staff account (Nova Staff or app:assign-executive-director): nobody chooses
 * the password for them, they set it through Nova's password reset page. The link is
 * created when the email is sent (single use, auth.passwords expiry); "Forgot your
 * password?" issues a new one. No member data in the body.
 */
class StaffAccountCreated extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct()
    {
        // Only once the account is committed (Nova creates it inside a transaction).
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
            ->subject('Your ProPerx Alliance portal account')
            ->greeting('Hello,')
            ->line('A staff account has been created for you in the ProPerx Alliance member portal.')
            ->line('Choose your password to sign in. You will then set up two-factor authentication.')
            ->action('Set your password', $url)
            ->line("The link works once and expires in {$minutes} minutes. After that, use \"Forgot your password?\" on the sign-in page.")
            ->salutation("Regards,\nProPerx Alliance");
    }
}
