<?php

namespace App\Notifications;

use App\Enums\AgencyTier;
use App\Enums\RateType;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * To the principal once the agency is enrolled (Agency spec 7): welcome, the receipt (dues, the
 * credit card surcharge on its own line, tier, people, renewal date) and their own one-time link
 * to set a password and two-factor sign-in. Sent at once (notifyNow), never queued: the token
 * exists only in this email. No names or agency details. Wording: docs/agency-emails.md.
 */
class AgencyWelcome extends Notification
{
    /**
     * @param  array{subtotal: int, surcharge: int, total: int, tier: string, rate_type: string, headcount: int, partner_rate_applied?: bool}  $receipt
     */
    public function __construct(
        private readonly string $token,
        private readonly CarbonInterface $expiresAt,
        private readonly array $receipt,
        private readonly ?CarbonInterface $renewsAt,
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
        return (new MailMessage)
            ->subject('Welcome to ProPerX Alliance: your agency is enrolled')
            ->greeting('Welcome to ProPerX Alliance')
            ->line('Thank you: your agency is enrolled and your payment has been received.')
            ->line(new HtmlString($this->receiptTable()))
            ->line('Everyone else on your list gets their own email invitation to set up their member account. We remind those who have not done so after 3 and 7 days, and copy you on the second reminder.')
            ->line('Your own account is ready too. Choose your password, then turn on two-factor sign-in with an authenticator app.')
            ->action('Set up my account', route('join.invitation', $this->token))
            ->line('This link works once and expires on '.$this->expiresAt->timezone(config('portal.schedule_timezone'))->format('F j, Y').'. After that, use "Forgot your password?" on the sign-in page.')
            ->salutation("Regards,\nProPerX Alliance");
    }

    /**
     * Markdown table, rendered by the mail layout. Amounts and plan only.
     */
    private function receiptTable(): string
    {
        $tier = AgencyTier::tryFrom($this->receipt['tier']);
        $rate = ($this->receipt['partner_rate_applied'] ?? false)
            ? 'Partner rate'
            : RateType::tryFrom($this->receipt['rate_type'])?->label().' rate';
        $rows = [
            ['Membership', trim(($tier?->label() ?? '').', '.$rate, ', ')],
            ['People', (string) $this->receipt['headcount']],
            ['Membership dues for the year', Money::format($this->receipt['subtotal'])],
        ];

        if ($this->receipt['surcharge'] > 0) {
            $rows[] = ['Credit card surcharge', Money::format($this->receipt['surcharge'])];
        }

        $rows[] = ['**Total paid**', '**'.Money::format($this->receipt['total']).'**'];

        if ($this->renewsAt !== null) {
            $rows[] = ['Renews on', $this->renewsAt->timezone(config('portal.schedule_timezone'))->format('F j, Y')];
        }

        return "| Receipt | |\n|:--|--:|\n".implode("\n", array_map(fn (array $row) => '| '.$row[0].' | '.$row[1].' |', $rows));
    }
}
