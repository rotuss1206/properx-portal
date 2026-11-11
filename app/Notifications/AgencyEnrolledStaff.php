<?php

namespace App\Notifications;

use App\Models\Agency;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Laravel\Nova\Nova;

/**
 * "New agency enrolled" by email to portal.invitations.staff_email, when it is set (staff always
 * get it in Nova). The agency's id, tier and number of people only: no names or identifiers.
 */
class AgencyEnrolledStaff extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly int $agencyId)
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
        $agency = Agency::query()->find($this->agencyId);
        $headcount = (int) $agency?->headcount;

        return (new MailMessage)
            ->subject('New agency enrolled')
            ->greeting('Hello,')
            ->line("A new agency has enrolled and paid (agency #{$this->agencyId}): ".($agency?->tier?->label() ?? 'tier unknown').', '.$headcount.' '.($headcount === 1 ? 'person' : 'people').'.')
            ->line('Their invitations have been sent. Licensed producers are waiting for their NPN and state to be checked.')
            ->action('Open the agency', url(Nova::url('/resources/agencies/'.$this->agencyId)))
            ->salutation("Regards,\nProPerX Alliance");
    }
}
