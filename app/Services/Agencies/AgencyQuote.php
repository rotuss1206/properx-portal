<?php

namespace App\Services\Agencies;

use App\Enums\AgencyTier;
use App\Enums\RateType;
use App\Support\Money;

/**
 * Annual agency dues; amounts are in cents.
 */
final readonly class AgencyQuote
{
    public function __construct(
        public int $headcount,
        public AgencyTier $tier,
        public RateType $rateType,
        /** Tier rate per person before any partner code. */
        public int $tierRate,
        /** Rate per person actually charged. */
        public int $rate,
        /** A partner code was given and is valid. */
        public bool $partnerCodeValid,
        /** The partner rate was lower than the tier rate and replaced it. */
        public bool $partnerRateApplied,
        /** Rate × headcount. */
        public int $subtotal,
        /** Credit card surcharge (zero for debit, prepaid, unknown and ACH). */
        public int $surcharge,
        public int $total,
    ) {}

    /**
     * Stripe price for the tier and rate type (looked up by lookup key unless a price ID is configured).
     *
     * @return array{lookup_key: string, price_id: ?string}
     */
    public function stripePrice(): array
    {
        return $this->tier->stripePrice($this->rateType);
    }

    /**
     * E.g. "12 people · Agency Plus · $149 each · $1,788 a year".
     */
    public function summary(): string
    {
        return sprintf(
            '%d people · %s · %s each · %s a year',
            $this->headcount,
            $this->tier->label(),
            Money::format($this->rate),
            Money::format($this->subtotal),
        );
    }
}
