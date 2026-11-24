<?php

namespace App\Services\Agencies;

use App\Enums\AgencyTier;
use App\Enums\RateType;
use App\Models\Agency;
use App\Models\PartnerCode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Agency dues (no Stripe calls): the tier follows the headcount, founding rates apply to
 * payments before the cutoff, a valid partner code caps the rate at its tier's partner rate,
 * credit cards pay a surcharge, and an agency always pays for at least
 * agency.minimum_headcount members (billableSeats). Rates and prices: config/agency.php.
 */
class AgencyPricingService
{
    /**
     * @param  CarbonInterface|null  $paidAt  when the payment is completed (default: now)
     * @param  string|null  $cardFunding  Stripe PaymentMethod card.funding; null for ACH or not known yet
     * @param  RateType|null  $rateType  a locked rate type (renewals); by default it follows $paidAt
     * @param  string|null  $payerState  the paying agency's state (legal address): no surcharge in an exempt state
     * @param  int  $employees  how many of the people are agency employees (billed only with agency.bill_employees)
     *
     * @throws HeadcountTooLowException when there is nobody (an agency has at least its principal)
     */
    public function quote(
        int $headcount,
        ?CarbonInterface $paidAt = null,
        ?PartnerCode $partnerCode = null,
        ?string $cardFunding = null,
        ?RateType $rateType = null,
        ?string $payerState = null,
        int $employees = 0,
    ): AgencyQuote {
        if ($headcount < 1) {
            throw HeadcountTooLowException::for($headcount, 1);
        }

        $paidAt ??= now();
        $tier = AgencyTier::forHeadcount(max($headcount, (int) config('agency.minimum_headcount')));
        $rateType ??= $this->rateTypeFor($paidAt);
        $tierRate = $tier->rate($rateType);

        // Partner codes do not stack: the lower of the tier's partner rate and the tier rate.
        $partnerCodeValid = $partnerCode?->isValid($paidAt) ?? false;
        $partnerRate = self::partnerRate($tier);
        $rate = $partnerCodeValid ? min($tierRate, $partnerRate) : $tierRate;
        $seats = self::billableSeats($headcount, $employees);

        $subtotal = $rate * $seats;
        $surcharge = $this->surcharge($subtotal, $cardFunding, $payerState);

        return new AgencyQuote(
            headcount: $headcount,
            tier: $tier,
            rateType: $rateType,
            tierRate: $tierRate,
            rate: $rate,
            partnerCodeValid: $partnerCodeValid,
            partnerRateApplied: $partnerCodeValid && $partnerRate <= $tierRate,
            seats: $seats,
            billedPeople: self::billedPeople($headcount, $employees),
            subtotal: $subtotal,
            surcharge: $surcharge,
            total: $subtotal + $surcharge,
        );
    }

    /**
     * Dues for an enrolled agency: its current headcount and its locked rate type.
     *
     * @throws HeadcountTooLowException
     */
    public function quoteForAgency(Agency $agency, ?CarbonInterface $at = null, ?string $cardFunding = null): AgencyQuote
    {
        return $this->quote(
            headcount: max(1, $agency->headcount),
            employees: $agency->employeeCount(),
            paidAt: $at,
            partnerCode: $agency->partnerCode,
            cardFunding: $cardFunding,
            rateType: $agency->rate_type,
            payerState: $agency->legalState(),
        );
    }

    /**
     * People the agency is billed for before the minimum: everyone, or only its licensed producers
     * while agency employees are not billed (agency.bill_employees).
     */
    public static function billedPeople(int $people, int $employees): int
    {
        return (bool) config('agency.bill_employees') ? $people : max(0, $people - $employees);
    }

    /**
     * Members the agency pays for: the people billed, never fewer than agency.minimum_headcount.
     * The one place this rule lives (wizard, Stripe quantity, adding and removing people, renewals).
     */
    public static function billableSeats(int $people, int $employees): int
    {
        return max((int) config('agency.minimum_headcount'), self::billedPeople($people, $employees));
    }

    /**
     * The partner rate of a tier, in cents (agency.partner_rates).
     */
    public static function partnerRate(AgencyTier $tier): int
    {
        return (int) config('agency.partner_rates.'.$tier->value);
    }

    public function rateTypeFor(CarbonInterface $paidAt): RateType
    {
        $cutoff = CarbonImmutable::parse(config('agency.founding_cutoff'), config('agency.founding_cutoff_timezone'));

        return $paidAt->lt($cutoff) ? RateType::Founding : RateType::Standard;
    }

    /**
     * Rounded half up to the cent; only for cards whose funding is "credit", and never for a
     * payer in a state listed in agency.surcharge_exempt_states.
     */
    public function surcharge(int $amount, ?string $cardFunding, ?string $payerState = null): int
    {
        if ($cardFunding !== 'credit' || ! $this->surchargeApplies($payerState)) {
            return 0;
        }

        return intdiv($amount * (int) config('agency.credit_card_surcharge_basis_points') + 5000, 10000);
    }

    /**
     * Whether a credit card from a payer in this state pays the surcharge at all.
     */
    public function surchargeApplies(?string $payerState): bool
    {
        return ! in_array(strtoupper(trim((string) $payerState)), (array) config('agency.surcharge_exempt_states'), true);
    }
}
