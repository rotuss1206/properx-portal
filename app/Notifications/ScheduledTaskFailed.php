<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alert to the Executive Director when a scheduled job fails (ТЗ, розділ 3.5).
 * Sent synchronously on purpose: the queue itself may be what is broken.
 */
class ScheduledTaskFailed extends Notification
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public readonly string $task,
        public readonly array $errors,
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
        $message = (new MailMessage)
            ->error()
            ->subject("[ProPerx portal] Scheduled task failed: {$this->task}")
            ->line("The scheduled task \"{$this->task}\" finished with errors at ".now()->toDayDateTimeString().' (UTC).');

        foreach (array_slice($this->errors, 0, 20) as $error) {
            $message->line('• '.$error);
        }

        if (count($this->errors) > 20) {
            $message->line('…and '.(count($this->errors) - 20).' more. See the application log for details.');
        }

        return $message;
    }
}
