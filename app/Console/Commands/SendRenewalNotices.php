<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Models\MemberProfile;
use App\Models\User;
use App\Notifications\Member\RenewalDue;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * In-app "renewal due" notices (member area): once per paid period, when it ends within
 * the next self::DAYS (30) days. Individual members get their own; for an agency, the
 * principal gets the agency's. Idempotent: a notice for the same period end is never repeated.
 * Ids and dates only (no PHI); nothing is logged.
 */
#[Signature('app:send-renewal-notices')]
#[Description('Notify members in the portal when their paid membership period ends within 30 days')]
class SendRenewalNotices extends Command
{
    public const DAYS = 30;

    public function handle(): int
    {
        $from = now()->startOfDay();
        $until = now()->addDays(self::DAYS)->endOfDay();
        $sent = 0;

        MemberProfile::query()
            ->whereNull('agency_id')
            ->whereBetween('paid_through_at', [$from, $until])
            ->with('user')
            ->chunkById(200, function ($profiles) use (&$sent) {
                foreach ($profiles as $profile) {
                    $sent += (int) $this->notify($profile->user, $profile->paid_through_at->toDateString());
                }
            });

        Agency::query()
            ->whereNotNull('principal_user_id')
            ->whereBetween('paid_through_at', [$from, $until])
            ->with('principal')
            ->chunkById(200, function ($agencies) use (&$sent) {
                foreach ($agencies as $agency) {
                    $sent += (int) $this->notify($agency->principal, $agency->paid_through_at->toDateString());
                }
            });

        $this->info("Renewal notices sent: {$sent}.");

        return self::SUCCESS;
    }

    private function notify(?User $user, string $paidThrough): bool
    {
        if ($user === null) {
            return false;
        }

        $alreadySent = $user->notifications()
            ->where('type', RenewalDue::class)
            ->get(['data'])
            ->contains(fn ($notification) => ($notification->data['paid_through'] ?? null) === $paidThrough);

        if ($alreadySent) {
            return false;
        }

        $user->notify(new RenewalDue($paidThrough));

        return true;
    }
}
