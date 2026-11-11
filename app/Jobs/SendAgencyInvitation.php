<?php

namespace App\Jobs;

use App\Models\MemberProfile;
use App\Services\Agencies\AgencyInvitations;
use App\Services\ServiceAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The first invitation of one person on a newly enrolled agency (Agency spec 7). The queue holds
 * the profile id and, for the principal, the receipt's amounts; the token is made when the email
 * is sent, so it never sits in the jobs table. Sent once: a person who already has an invitation
 * is skipped, so the browser and the webhook enrolling the same agency, or a retried job, send
 * nothing twice.
 */
class SendAgencyInvitation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * @param  array<string, mixed>|null  $receipt
     */
    public function __construct(public int $memberProfileId, public ?array $receipt = null) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(AgencyInvitations $invitations, ServiceAccount $serviceAccounts): void
    {
        $profile = MemberProfile::query()->find($this->memberProfileId);

        if ($profile === null) {
            return;
        }

        // Audited as the system's own work, like the Stripe webhook.
        $actor = rescue(fn () => $serviceAccounts->user(), null, false);

        $setActor = $actor !== null && Auth::user() === null;

        if ($setActor) {
            Auth::setUser($actor);
        }

        try {
            $invitations->sendFirst($profile, $this->receipt);
        } finally {
            if ($setActor) {
                Auth::forgetUser();
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Agency invitation could not be sent', [
            'member_profile' => $this->memberProfileId,
            'exception' => $exception ? $exception::class : null,
        ]);
    }
}
