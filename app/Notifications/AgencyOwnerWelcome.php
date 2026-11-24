<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * The agency owner's welcome, the client's text word for word (go-live requirements, "Agency owner
 * welcome"), sent as soon as the enrollment is paid. The receipt and the one-time link to set up the
 * account follow in AgencyWelcome. Personal data: the owner's first name in the greeting (the
 * client's template). The health enrollment application is attached when portal.agency_welcome.attachment
 * points at a readable file.
 */
class AgencyOwnerWelcome extends Notification
{
    public const SUBJECT = 'Welcome to ProPerX Alliance! Your Agency Account Is Ready';

    /** The client's signature block. */
    public const SIGNATURE = ['Anne Glorioso', 'Founder and CEO', 'ProPerX Alliance', '440-776-7379 | hello@properx.org | properx.org'];

    public function __construct(private readonly string $firstName) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $login = (string) (config('portal.agency_welcome.login_url') ?: url('/nova/login'));
        $greeting = trim($this->firstName) === '' ? 'Hi,' : 'Hi '.trim($this->firstName).',';

        $mail = (new MailMessage)
            ->subject(self::SUBJECT)
            ->greeting($greeting)
            ->line('Welcome to ProPerX Alliance! We\'re glad to have you and your agency on board.')
            ->line('Your agency account is ready, and everything you need to get started is below.')
            ->line('**1. ACCESS YOUR AGENCY PORTAL**  ')
            ->line('Your agency portal lists the employees you\'ve enrolled in the alliance and lets you add your W2 workers.')
            ->action('Log in to your agency portal', $login)
            ->line('**2. GIVE YOUR EMPLOYEES PORTAL ACCESS**  ')
            ->line('You can grant employees access to log in under their own accounts, affiliated with your agency under "Profile."')
            ->line('**3. ENROLL IN HEALTH COVERAGE AND OTHER BENEFITS**  ')
            ->line('Your portal gives you the resources to enroll in health coverage and our other benefits, and it stores each employee\'s enrollment details in their individual member portal.')
            ->line('**ACTION REQUIRED:** Please complete the attached health enrollment application and reply to this email with it on behalf of your agency. Your employees can enroll in health coverage and other benefits once we receive it.')
            ->line('Questions? Contact us using the details below or simply reply to this email.')
            ->line('Thank you for joining ProPerX Alliance. We look forward to growing together!')
            ->salutation("Warm regards,\n\n".implode("  \n", self::SIGNATURE));

        if (filled($replyTo = config('portal.agency_welcome.reply_to'))) {
            $mail->replyTo($replyTo);
        }

        $attachment = self::attachmentPath();

        if ($attachment !== null) {
            $mail->attach($attachment, ['as' => basename($attachment)]);
        } else {
            Log::warning('The agency welcome was sent without the health enrollment application.', ['reason' => blank(config('portal.agency_welcome.attachment')) ? 'not_configured' : 'file_missing']);
        }

        return $mail;
    }

    /**
     * The configured attachment, when it can be read.
     */
    public static function attachmentPath(): ?string
    {
        $path = trim((string) config('portal.agency_welcome.attachment'));

        if ($path === '') {
            return null;
        }

        $path = preg_match('#^([a-zA-Z]:[\\\\/]|/)#', $path) === 1 ? $path : base_path($path);

        return is_file($path) && is_readable($path) ? $path : null;
    }
}
