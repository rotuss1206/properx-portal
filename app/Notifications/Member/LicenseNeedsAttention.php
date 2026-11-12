<?php

namespace App\Notifications\Member;

use App\Enums\LicenseVerification;
use App\Models\MemberProfile;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Laravel\Nova\Nova;

/**
 * Staff could not verify a person's NPN and resident state (Agency spec 8.3, 9). Two recipients:
 *  - the person (kind "own"): always in the bell; by email too when nobody else is told, i.e. an
 *    individual member or the agency's own principal;
 *  - the agency's principal (kind "agency") for one of their people: bell and email.
 *
 * The payload is the member profile id only; the reason stays encrypted on the profile and is read
 * when the bell or the email is written. The subject names nobody; the body carries staff's reason
 * (the same exception as the rejection email of a license document) and never a name or an NPN.
 */
class LicenseNeedsAttention extends Notification implements ShouldQueue
{
    use Queueable;

    public const OWN = 'own';

    public const AGENCY = 'agency';

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly int $profileId,
        public readonly string $kind,
        public readonly bool $email,
    ) {
        $this->afterCommit();
    }

    /**
     * Who is told about this profile: the person, and the principal for agency people.
     */
    public static function send(MemberProfile $profile): void
    {
        $person = $profile->user;
        $principal = $profile->agency_id !== null ? $profile->agency?->principal : null;
        $principalIsSomeoneElse = $principal !== null && $person !== null && $principal->isNot($person);

        $person?->notify(new self($profile->getKey(), self::OWN, email: ! $principalIsSomeoneElse));

        if ($principalIsSomeoneElse) {
            $principal->notify(new self($profile->getKey(), self::AGENCY, email: true));
        }
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->email ? ['database', 'mail'] : ['database'];
    }

    /**
     * Not once the license was verified or went back to review in the meantime.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $this->profile()?->license_verification === LicenseVerification::NeedsAttention;
    }

    /**
     * @return array{profile_id: int, kind: string}
     */
    public function toArray(object $notifiable): array
    {
        return ['profile_id' => $this->profileId, 'kind' => $this->kind];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $reason = (string) $this->profile()?->license_verification_note;
        $mail = (new MailMessage)->greeting('Hello,');

        if ($this->kind === self::AGENCY) {
            $mail->subject('Action needed: a license on your agency\'s list')
                ->line('ProPerX Alliance checked the NPN and resident state of one person on your agency\'s list and could not verify them.')
                ->line('What to fix: '.$reason)
                ->line('You will see who under the bell in the member portal. The person can correct their NPN or resident state in their profile, and we will check it again.')
                ->action('Open the member portal', self::url($notifiable, null));
        } else {
            $mail->subject('Action needed: your license could not be verified')
                ->line('ProPerX Alliance checked your NPN and resident state and could not verify them.')
                ->line('What to fix: '.$reason)
                ->line('Please correct them in your profile, and we will check them again.')
                ->action('Update my profile', self::url($notifiable, 'business'));
        }

        return $mail->salutation("Regards,\nProPerX Alliance Membership Team");
    }

    /**
     * The member area's profile (the section with the NPN) or dashboard; Nova's profile while
     * the member area is off.
     */
    public static function url(User $user, ?string $section): string
    {
        if (! config('portal.member_area.enabled')) {
            $profile = $user->memberProfile;

            return $profile ? url(Nova::path().'/resources/member-profiles/'.$profile->getKey()) : url(Nova::path());
        }

        return $section === null ? route('member.dashboard') : route('member.profile', ['edit' => $section]);
    }

    private function profile(): ?MemberProfile
    {
        return MemberProfile::query()->find($this->profileId);
    }
}
