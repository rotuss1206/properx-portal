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
 * License expiry reminder, 60 / 30 / 7 days ahead (Spec §2, §3.5).
 * MailMessage renders both the HTML and the plain-text part.
 */
class LicenseExpiryReminder extends Notification implements ShouldQueue
{
    use Queueable;

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
        $expires = $this->profile->license_expires_at->toFormattedDateString();
        $profileUrl = self::profileUrl($this->profile);
        $preferencesUrl = self::preferencesUrl($this->profile);

        return (new MailMessage)
            ->subject("Your insurance license expires in {$this->type->value} days")
            ->greeting("Hello {$this->profile->first_name},")
            ->line("Our records show that your license {$this->profile->license_number} ({$this->profile->license_state}) expires on **{$expires}**.")
            ->line('Please renew it in time and upload the renewed license document to your member profile so your ProPerx Alliance membership stays in good standing.')
            ->action('Open my member profile', $profileUrl)
            ->line('If you have already renewed, you can ignore this message once the new document is uploaded.')
            ->salutation("Regards,\nProPerx Alliance Membership Team")
            ->line("To stop license expiry emails, change your notification preferences: {$preferencesUrl}")
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
