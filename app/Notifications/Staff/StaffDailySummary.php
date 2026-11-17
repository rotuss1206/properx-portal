<?php

namespace App\Notifications\Staff;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Laravel\Nova\Nova;

/**
 * The morning summary for one person on the staff (app:send-staff-summary): what waits for them,
 * as counts only (no names, emails or other personal data), and a link to Nova.
 */
class StaffDailySummary extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  list<array{label: string, count: int}>  $lines
     */
    public function __construct(public readonly array $lines)
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
        $mail = (new MailMessage)
            ->subject('ProPerX Alliance: what waits for you today')
            ->greeting('Good morning,')
            ->line('Here is what waits for you in the portal:');

        foreach ($this->lines as $line) {
            $mail->line('• '.$line['count'].' '.$line['label']);
        }

        return $mail
            ->action('Open the portal', url(Nova::path()))
            ->salutation("Regards,\nProPerX Alliance");
    }
}
