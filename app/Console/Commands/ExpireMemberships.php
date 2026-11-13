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
 * After a failed renewal payment (H2): reminders while the grace period runs
 * (portal.renewals.grace_reminder_days before access ends), and once portal.payments.grace_days are
 * over the membership expires: an agency becomes inactive with its people, the principal or the
 * individual member is told it expired, the agency's people that it ended. Paying later restores
 * everything (the payment's webhook). Idempotent; stops at the time budget.
 */
#[Signature('app:expire-memberships')]
#[Description('Remind past-due members and agencies, and expire them once the grace period is over')]
class ExpireMemberships extends Command
{
    use RunsWithinBudget;

    public function handle(MembershipRenewals $renewals): int
    {
        $this->startBudget();
        $counts = ['reminded' => 0, 'expired' => 0];

        $this->within(
            Agency::query()->where('status', AgencyStatus::PastDue)->with('principal'),
            'Agency',
            function (Agency $agency) use ($renewals, &$counts) {
                $outcome = $renewals->checkPastDueAgency($agency);
                $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
            },
        );

        $this->within(
            MemberProfile::query()->whereNull('agency_id')->where('payment_status', PaymentStatus::PastDue)->with('user'),
            'Member profile',
            function (MemberProfile $profile) use ($renewals, &$counts) {
                $outcome = $renewals->checkPastDueIndividual($profile);
                $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
            },
        );

        $this->info("Overdue reminders: {$counts['reminded']}, expired: {$counts['expired']}, errors: ".count($this->errors).'.');

        return $this->finishBudget();
    }
}
