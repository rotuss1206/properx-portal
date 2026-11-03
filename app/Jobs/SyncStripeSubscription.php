<?php

namespace App\Jobs;

use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\MemberProfile;
use App\Services\ServiceAccount;
use App\Services\Stripe\StripeWebhookHandler;
use App\Services\Stripe\SubscriptionReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * After a paid application: reads the member's subscription from Stripe and sets the paid period
 * and payment status, in case Stripe's events are late or were missed. A subscription belonging to
 * another customer than the one on file changes nothing.
 */
class SyncStripeSubscription implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public int $memberProfileId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(SubscriptionReader $reader, StripeWebhookHandler $handler, ServiceAccount $serviceAccounts): void
    {
        $profile = MemberProfile::find($this->memberProfileId);

        if ($profile === null || blank($profile->stripe_subscription_id) || ! SubscriptionReader::isConfigured()) {
            return;
        }

        $serviceAccount = $serviceAccounts->user();
        $subscription = $reader->read($profile->stripe_subscription_id);

        if ($subscription['customer'] !== $profile->stripe_customer_id) {
            AuditLog::record('stripe_subscription_mismatch', $profile, $serviceAccount);

            return;
        }

        Auth::setUser($serviceAccount);

        try {
            // The API's answer is the state right now, so older events arriving later count as stale.
            $handler->applyFacts(
                $profile,
                PaymentStatus::fromSubscriptionStatus($subscription['status']),
                $subscription['period_end'] === null ? null : Carbon::createFromTimestamp($subscription['period_end']),
                now(),
                $serviceAccount,
            );
        } finally {
            Auth::forgetUser();
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Stripe subscription sync failed', [
            'member_profile' => $this->memberProfileId,
            'exception' => $exception ? $exception::class : null,
        ]);
    }
}
