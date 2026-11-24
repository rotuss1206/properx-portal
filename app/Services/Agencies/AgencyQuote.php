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
        /** A valid partner code set the rate (its partner rate was not above the tier rate). */
        public bool $partnerRateApplied,
        /** Members billed: the people billed, at least agency.minimum_headcount (the Stripe quantity). */
        public int $seats,
        /** People billed before the minimum: everyone, or the producers while employees are not billed. */
        public int $billedPeople,
        /** Rate × seats. */
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
     * The agency pays for more members than people billed (the two-member minimum).
     */
    public function minimumApplied(): bool
    {
        return $this->seats > $this->billedPeople;
    }

    /**
     * E.g. "Minimum 2 members: $159 × 2".
     */
    public function minimumLine(): string
    {
        return sprintf('Minimum %d members: %s × %d', $this->seats, Money::format($this->rate), $this->seats);
    }

    /**
     * E.g. "12 people · Agency Plus · $149 each · $1,788 a year", "1 person · Agency · minimum 2
     * members at $159 · $318 a year", or with employees not billed "5 people · Agency · $159 each
     * for 3 billed · $477 a year".
     */
    public function summary(): string
    {
        $people = $this->headcount.' '.($this->headcount === 1 ? 'person' : 'people');

        if ($this->minimumApplied()) {
            return sprintf('%s · %s · minimum %d members at %s · %s a year', $people, $this->tier->label(), $this->seats, Money::format($this->rate), Money::format($this->subtotal));
        }

        return sprintf(
            '%s · %s · %s each%s · %s a year',
            $people,
            $this->tier->label(),
            Money::format($this->rate),
            $this->seats !== $this->headcount ? ' for '.$this->seats.' billed' : '',
            Money::format($this->subtotal),
        );
    }
}
