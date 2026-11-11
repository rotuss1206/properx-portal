<?php

namespace App\Notifications\Member;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To an agency's principal about their people's invitations (Agency spec 7), in the member area's
 * bell and by email:
 *  - not_accepted: the copy of the second reminder (people who have not set up their account);
 *  - requested: someone opened an expired or used link and asked for a new invitation.
 * The payload holds member profile ids only; App\Member\NotificationFeed shows the names in the
 * portal. The email names nobody. Wording: docs/agency-emails.md.
 */
class AgencyInvitationNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public const NOT_ACCEPTED = 'not_accepted';

    public const REQUESTED = 'requested';

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * @param  list<int>  $profileIds
     */
    public function __construct(public readonly string $kind, public readonly array $profileIds)
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
     * @return array{kind: string, profile_ids: list<int>}
     */
    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, 'profile_ids' => $this->profileIds];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = count($this->profileIds);
        $message = (new MailMessage)->greeting('Hello,');

        if ($this->kind === self::REQUESTED) {
            $message
                ->subject('A new ProPerX Alliance invitation was requested')
                ->line('Someone on your agency\'s list opened an invitation link that had expired or had already been used, and asked for a new invitation.')
                ->line('ProPerX staff have been told and will send a new link. You will see who asked under the bell in the member portal.');
        } else {
            $message
                ->subject('Invitations not yet accepted')
                ->line(($count === 1 ? 'One person' : "{$count} people").' on your agency\'s list '.($count === 1 ? 'has' : 'have').' not set up '.($count === 1 ? 'their member account' : 'their member accounts').' yet. We sent '.($count === 1 ? 'them' : 'each of them').' a second reminder today.')
                ->line('You will see who under the bell in the member portal. If an email address is wrong or someone needs a new link, contact ProPerX.');
        }

        return $message
            ->action('Open the member portal', route('member.dashboard'))
            ->salutation("Regards,\nProPerX Alliance");
    }
}
