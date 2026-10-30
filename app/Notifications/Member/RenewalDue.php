<?php

namespace App\Notifications\Member;

use Illuminate\Notifications\Notification;

/**
 * In-app notice (member area) that the paid membership period ends within 30 days, sent once
 * per period by app:send-renewal-notices. Holds the period's end date only.
 */
class RenewalDue extends Notification
{
    public function __construct(public readonly string $paidThrough) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{paid_through: string}
     */
    public function toArray(object $notifiable): array
    {
        return ['paid_through' => $this->paidThrough];
    }
}
