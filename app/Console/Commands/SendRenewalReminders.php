<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsWithinBudget;
use App\Enums\AgencyStatus;
use App\Enums\PaymentStatus;
use App\Models\Agency;
use App\Models\MemberProfile;
use App\Services\Memberships\MembershipRenewals;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Renewal reminders (H1): portal.renewals.reminder_days before the paid period ends (14, 7 and 1 by
 * default), to agencies' principals and to individual members with a Stripe subscription; email
 * and the bell. Each reminder is logged first (once per person, day and period). Stops at the
 * time budget; the next run continues.
 */
#[Signature('app:send-renewal-reminders')]
#[Description('Remind agencies and individual members before their membership renews')]
class SendRenewalReminders extends Command
{
    use RunsWithinBudget;

    public function handle(MembershipRenewals $renewals): int
    {
        $this->startBudget();
        $sent = 0;
        $until = now()->addDays((int) max([1, ...(array) config('portal.renewals.reminder_days')]) + 1);

        $this->within(
            Agency::query()->where('status', AgencyStatus::Active)->whereNotNull('principal_user_id')->whereBetween('paid_through_at', [now(), $until])->with('principal'),
            'Agency',
            function (Agency $agency) use ($renewals, &$sent) {
                $sent += (int) $renewals->remindAgency($agency);
            },
        );

        $this->within(
            MemberProfile::query()->whereNull('agency_id')->whereNotNull('stripe_customer_id')->where('payment_status', PaymentStatus::Active)->whereBetween('paid_through_at', [now(), $until])->with('user'),
            'Member profile',
            function (MemberProfile $profile) use ($renewals, &$sent) {
                $sent += (int) $renewals->remindIndividual($profile);
            },
        );

        $this->info("Renewal reminders sent: {$sent}, errors: ".count($this->errors).'.');

        return $this->finishBudget();
    }
}
