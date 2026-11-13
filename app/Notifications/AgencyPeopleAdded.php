<?php

namespace App\Notifications;

use App\Enums\AgencyTier;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * The principal's receipt after adding people (G3): how many, the tier and rate, the prorated
 * amount, the surcharge on its own line, the total and how it was paid. No names (each person
 * gets their own invitation).
 */
class AgencyPeopleAdded extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $people,
        public readonly string $tier,
        public readonly bool $tierChanged,
        public readonly int $rate,
        /** @var list<array{description: string, amount: int}> Stripe's proration lines */
        public readonly array $lines,
        public readonly int $subtotal,
        public readonly int $surcharge,
        public readonly int $total,
        public readonly string $periodEnds,
        public readonly string $paymentMethod,
        /** An existing member moved in by ProPerX staff: no invitation, they already have an account. */
        public readonly bool $moved = false,
    ) {
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
        $ends = CarbonImmutable::parse($this->periodEnds)->format('F j, Y');
        $rows = [
            [$this->moved ? 'Members moved to your agency' : 'People added', (string) $this->people],
        ];

        foreach ($this->lines as $line) {
            $rows[] = [str_replace('|', '/', $line['description']), Money::exact($line['amount'])];
        }

        $rows[] = ['Prorated dues until '.$ends, Money::exact($this->subtotal)];

        if ($this->surcharge > 0) {
            $rows[] = ['Credit card surcharge', Money::exact($this->surcharge)];
        }

        $rows[] = ['**Total paid**', '**'.Money::exact($this->total).'**'];
        $rows[] = ['Paid with', str_replace('|', '/', $this->paymentMethod)];

        $table = "| Receipt | |\n|:--|--:|\n".implode("\n", array_map(fn (array $row) => '| '.$row[0].' | '.$row[1].' |', $rows));
        $tier = AgencyTier::tryFrom($this->tier)?->label() ?? $this->tier;

        $mail = (new MailMessage)
            ->subject($this->moved ? 'A member was moved to your agency’s ProPerX Alliance membership' : 'People added to your agency’s ProPerX Alliance membership')
            ->greeting('Hello,')
            ->line($this->moved
                ? 'ProPerX has moved an existing ProPerX member to your agency, and the prorated payment has been received with your saved payment method. They keep their account, so no invitation is sent; you can see them under My agency.'
                : ($this->people === 1 ? 'One person has' : "{$this->people} people have").' been added to your agency, and the payment has been received. Each of them gets an email invitation to set up their account.')
            ->line(new HtmlString($table));

        if ($this->tierChanged) {
            $mail->line("Your agency has moved up to {$tier}: everyone's rate is now ".Money::format($this->rate).' a year, prorated above for the rest of the year.');
        }

        return $mail
            ->action('Open my agency', route('member.agency'))
            ->salutation("Regards,\nProPerX Alliance");
    }
}
