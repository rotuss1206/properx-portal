<?php

namespace App\Notifications\Member;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * Before the paid period ends (H1; days in portal.renewals.reminder_days): to an agency's
 * principal about the agency's renewal, or to an individual member about their own. Email and the
 * bell. Sent at once by app:send-renewal-reminders (never queued: the amount and the card's last
 * four digits stay out of the jobs table); the bell keeps the date and the days only.
 */
class RenewalReminder extends Notification
{
    public const AGENCY = 'agency';

    public const INDIVIDUAL = 'individual';

    /**
     * @param  array{people?: ?int, tier?: ?string, amount?: ?int, method?: ?string}  $facts
     */
    public function __construct(
        public readonly string $kind,
        public readonly int $days,
        public readonly string $paidThrough,
        public readonly array $facts = [],
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * @return array{kind: string, days: int, paid_through: string}
     */
    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, 'days' => $this->days, 'paid_through' => $this->paidThrough];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $date = CarbonImmutable::parse($this->paidThrough)->format('F j, Y');
        $when = $this->days === 1 ? 'tomorrow' : "in {$this->days} days";
        $agency = $this->kind === self::AGENCY;

        $rows = array_filter([
            'Renews on' => $date,
            'People' => $agency && isset($this->facts['people']) ? (string) $this->facts['people'] : null,
            'Tier' => $agency ? ($this->facts['tier'] ?? null) : null,
            'Amount' => isset($this->facts['amount']) ? Money::exact((int) $this->facts['amount']).' a year' : null,
            'Payment method' => $this->facts['method'] ?? 'The payment method on file',
        ]);

        // A markdown table, rendered by the mail layout (as the welcome email's receipt).
        $table = "| Renewal | |\n|:--|--:|\n".collect($rows)->map(fn ($value, $label) => "| {$label} | ".str_replace('|', '/', $value).' |')->implode("\n");

        return (new MailMessage)
            ->subject($agency ? 'Your agency’s ProPerX Alliance membership renews '.$when : 'Your ProPerX Alliance membership renews '.$when)
            ->greeting('Hello,')
            ->line($agency
                ? "Your agency's membership renews automatically {$when}. Here is what will be charged:"
                : "Your membership renews automatically {$when}. Here is what will be charged:")
            ->line(new HtmlString($table))
            ->line('To change the payment method or see your invoices, open Billing in the member portal.')
            ->action('Review billing', route('member.billing'))
            ->salutation("Regards,\nProPerX Alliance");
    }
}
