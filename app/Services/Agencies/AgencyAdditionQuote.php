<?php

namespace App\Services\Agencies;

use App\Enums\AgencyTier;
use Carbon\CarbonInterface;

/**
 * What adding people to an enrolled agency costs now (Agency spec 4, 8.1; G3), from Stripe's own
 * preview of the change: the subscription's quantity goes up and, when the headcount reaches a
 * higher tier, everyone moves to its (lower) price at once, all prorated for the rest of the paid
 * period. The credit card surcharge is added where it applies. A change that would come out below
 * zero (a cheaper tier for everyone) charges nothing and creates no credit (Agency spec 2): the new
 * price then applies from the next renewal. Amounts in cents.
 */
final readonly class AgencyAdditionQuote
{
    /**
     * @param  list<array{description: string, amount: int}>  $lines  Stripe's proration lines
     */
    public function __construct(
        public int $added,
        public int $headcountBefore,
        public int $headcountAfter,
        public AgencyTier $tierBefore,
        public AgencyTier $tierAfter,
        /** Rate per person a year after the change (tier, rate type, partner cap). */
        public int $rate,
        public CarbonInterface $periodEnds,
        /** The moment the proration is computed for; the charge uses the same, so the amount matches. */
        public int $prorationDate,
        public array $lines,
        /** The prorated change, never below zero. */
        public int $subtotal,
        /** True when the change would have credited the agency: nothing is charged and nothing credited. */
        public bool $noCharge,
        public int $surcharge,
        public int $total,
        /** e.g. "Visa credit card ending 4242"; the card's funding decides the surcharge. */
        public string $paymentMethod,
        public ?string $paymentMethodId,
        public string $subscriptionItemId,
    ) {}

    public function tierChanges(): bool
    {
        return $this->tierAfter !== $this->tierBefore;
    }
}
