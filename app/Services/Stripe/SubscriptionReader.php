<?php

namespace App\Services\Stripe;

use Stripe\StripeClient;

/**
 * Reads one subscription from the Stripe API (STRIPE_SECRET; a restricted read-only key is enough).
 * It takes no payments and changes nothing in Stripe (the agency prices are read by AgencyPriceCatalog).
 */
class SubscriptionReader
{
    public static function isConfigured(): bool
    {
        return filled(config('services.stripe.secret'));
    }

    /**
     * @return array{customer: ?string, status: string, period_end: ?int}
     */
    public function read(string $subscriptionId): array
    {
        $subscription = (new StripeClient((string) config('services.stripe.secret')))->subscriptions->retrieve($subscriptionId);

        // Newer API versions moved the period end from the subscription to its items.
        $ends = array_filter(
            [...array_map(fn ($item) => $item->current_period_end ?? null, $subscription->items->data ?? []), $subscription->current_period_end ?? null],
            fn ($end) => is_int($end) && $end > 0,
        );

        return [
            'customer' => is_string($subscription->customer) ? $subscription->customer : null,
            'status' => (string) $subscription->status,
            'period_end' => $ends === [] ? null : max($ends),
        ];
    }
}
