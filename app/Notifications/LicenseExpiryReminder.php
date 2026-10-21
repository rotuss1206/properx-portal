<?php

namespace App\Notifications;

use App\Enums\ReminderType;
use App\Models\MemberProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Laravel\Nova\Nova;
use Symfony\Component\Mime\Email;

/**
 * License expiry reminder, 60 / 30 / 7 days ahead (Spec 2, 3.5).
 *
 * The body carries no member data (no name, license number, state or date): it only
 * points to the portal, where the details are shown after sign-in (KEENEY PHI rule).
 */
class LicenseExpiryReminder extends Notification implements ShouldQueue
{
    use Queueable;

    /** Failed jobs get 3 tries on the platform; each must finish well within 120 s. */
    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly MemberProfile $profile,
        public readonly ReminderType $type,
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
        $preferencesUrl = self::preferencesUrl($this->profile);

        return (new MailMessage)
            ->subject('Action needed: your ProPerx Alliance license information')
            ->greeting('Hello,')
            ->line('There is an update about your license on file that needs your attention.')
            ->line('Please sign in to the member portal to review it and, if needed, upload your renewed license document.')
            ->action('Sign in to the member portal', self::profileUrl($this->profile))
            ->salutation("Regards,\nProPerx Alliance Membership Team")
            ->line("To stop these emails, change your notification preferences in the portal: {$preferencesUrl}")
            ->withSymfonyMessage(function (Email $message) use ($preferencesUrl) {
                $message->getHeaders()->addTextHeader('List-Unsubscribe', "<{$preferencesUrl}>");
            });
    }

    public static function profileUrl(MemberProfile $profile): string
    {
        return url(Nova::path()."/resources/member-profiles/{$profile->getKey()}");
    }

    /**
     * Notification preferences live on the profile edit form in Nova.
     */
    public static function preferencesUrl(MemberProfile $profile): string
    {
        return url(Nova::path()."/resources/member-profiles/{$profile->getKey()}/edit");
    }
}
