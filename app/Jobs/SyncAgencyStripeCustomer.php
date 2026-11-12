<?php

namespace App\Jobs;

use App\Models\Agency;
use App\Services\Stripe\BillingReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;

/**
 * The agency's billing email and legal name on its Stripe customer, after the principal changed
 * them (Agency spec 8.1, G2): invoices and receipts go to that address. The agency id only in the
 * queue; the values are read when the job runs, so the latest ones win. Retried while Stripe
 * does not answer.
 */
class SyncAgencyStripeCustomer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public readonly int $agencyId)
    {
        $this->afterCommit();
    }

    public function handle(BillingReader $stripe): void
    {
        $agency = Agency::query()->find($this->agencyId);

        if ($agency === null || blank($agency->stripe_customer_id) || ! BillingReader::isConfigured()) {
            return;
        }

        try {
            $stripe->updateCustomer($agency->stripe_customer_id, array_filter([
                'email' => $agency->billing_email,
                'name' => $agency->legal_name,
            ], 'filled'));
        } catch (ApiErrorException $e) {
            Log::warning('Agency Stripe customer could not be updated', ['agency' => $agency->getKey(), 'error' => $e::class, 'code' => $e->getStripeCode()]);

            throw $e;
        }
    }
}
