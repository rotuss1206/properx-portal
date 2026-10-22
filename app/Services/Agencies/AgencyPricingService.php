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
 * payments before the cutoff, a valid partner code caps the rate at the partner rate,
 * and credit cards pay a surcharge. Rates and prices: config/agency.php.
 */
class AgencyPricingService
{
    /**
     * @param  CarbonInterface|null  $paidAt  when the payment is completed (default: now)
     * @param  string|null  $cardFunding  Stripe PaymentMethod card.funding; null for ACH or not known yet
     * @param  RateType|null  $rateType  a locked rate type (renewals); by default it follows $paidAt
     *
     * @throws HeadcountTooLowException
     */
    public function quote(
        int $headcount,
        ?CarbonInterface $paidAt = null,
        ?PartnerCode $partnerCode = null,
        ?string $cardFunding = null,
        ?RateType $rateType = null,
    ): AgencyQuote {
        $minimum = (int) config('agency.minimum_headcount');

        if ($headcount < $minimum) {
            throw HeadcountTooLowException::for($headcount, $minimum);
        }

        $paidAt ??= now();
        $tier = AgencyTier::forHeadcount($headcount);
        $rateType ??= $this->rateTypeFor($paidAt);
        $tierRate = $tier->rate($rateType);

        // Partner codes do not stack: the lower of the partner rate and the tier rate.
        $partnerCodeValid = $partnerCode?->isValid($paidAt) ?? false;
        $rate = $partnerCodeValid ? min($tierRate, (int) config('agency.partner_rate')) : $tierRate;

        $subtotal = $rate * $headcount;
        $surcharge = $this->surcharge($subtotal, $cardFunding);

        return new AgencyQuote(
            headcount: $headcount,
            tier: $tier,
            rateType: $rateType,
            tierRate: $tierRate,
            rate: $rate,
            partnerCodeValid: $partnerCodeValid,
            partnerRateApplied: $rate < $tierRate,
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
            headcount: $agency->headcount,
            paidAt: $at,
            partnerCode: $agency->partnerCode,
            cardFunding: $cardFunding,
            rateType: $agency->rate_type,
        );
    }

    public function rateTypeFor(CarbonInterface $paidAt): RateType
    {
        $cutoff = CarbonImmutable::parse(config('agency.founding_cutoff'), config('agency.founding_cutoff_timezone'));

        return $paidAt->lt($cutoff) ? RateType::Founding : RateType::Standard;
    }

    /**
     * Rounded half up to the cent; only for cards whose funding is "credit".
     */
    public function surcharge(int $amount, ?string $cardFunding): int
    {
        if ($cardFunding !== 'credit') {
            return 0;
        }

        return intdiv($amount * (int) config('agency.credit_card_surcharge_basis_points') + 5000, 10000);
    }
}
