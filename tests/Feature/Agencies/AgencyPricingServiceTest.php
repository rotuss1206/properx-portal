<?php

namespace Tests\Feature\Agencies;

use App\Enums\AgencyTier;
use App\Enums\RateType;
use App\Models\Agency;
use App\Models\PartnerCode;
use App\Services\Agencies\AgencyPricingService;
use App\Services\Agencies\HeadcountTooLowException;
use App\Support\Money;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AgencyPricingServiceTest extends TestCase
{
    private AgencyPricingService $pricing;

    private CarbonImmutable $foundingDate;

    private CarbonImmutable $standardDate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pricing = app(AgencyPricingService::class);
        $this->foundingDate = CarbonImmutable::parse('2026-10-22 12:00', 'America/New_York');
        $this->standardDate = CarbonImmutable::parse('2027-03-01 12:00', 'America/New_York');
    }

    /**
     * @return iterable<string, array{int, AgencyTier, int, int}>
     */
    public static function foundingExamples(): iterable
    {
        // headcount, tier, rate, annual total (cents)
        yield 'spec: 4 people' => [4, AgencyTier::Agency, 15900, 63600];
        yield 'spec: 12 people' => [12, AgencyTier::AgencyPlus, 14900, 178800];
        yield 'spec: 60 people' => [60, AgencyTier::AgencyEnterprise, 12900, 774000];
        yield 'minimum: 2 people' => [2, AgencyTier::Agency, 15900, 31800];
        yield 'boundary: 9 people' => [9, AgencyTier::Agency, 15900, 143100];
        yield 'boundary: 10 people' => [10, AgencyTier::AgencyPlus, 14900, 149000];
        yield 'boundary: 49 people' => [49, AgencyTier::AgencyPlus, 14900, 730100];
        yield 'boundary: 50 people' => [50, AgencyTier::AgencyEnterprise, 12900, 645000];
    }

    #[DataProvider('foundingExamples')]
    public function test_founding_rates_by_headcount(int $headcount, AgencyTier $tier, int $rate, int $total): void
    {
        $quote = $this->pricing->quote($headcount, $this->foundingDate);

        $this->assertSame($tier, $quote->tier);
        $this->assertSame(RateType::Founding, $quote->rateType);
        $this->assertSame($rate, $quote->rate);
        $this->assertSame($total, $quote->subtotal);
        $this->assertSame($total, $quote->total);
        $this->assertSame(0, $quote->surcharge);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function standardExamples(): iterable
    {
        yield '9 people' => [9, 17900];
        yield '10 people' => [10, 15900];
        yield '49 people' => [49, 15900];
        yield '50 people' => [50, 13900];
    }

    #[DataProvider('standardExamples')]
    public function test_standard_rates_by_headcount(int $headcount, int $rate): void
    {
        $quote = $this->pricing->quote($headcount, $this->standardDate);

        $this->assertSame(RateType::Standard, $quote->rateType);
        $this->assertSame($rate, $quote->rate);
        $this->assertSame($rate * $headcount, $quote->total);
    }

    public function test_at_least_two_people(): void
    {
        $this->expectException(HeadcountTooLowException::class);

        $this->pricing->quote(1, $this->foundingDate);
    }

    /**
     * @return iterable<string, array{string, string, RateType}>
     */
    public static function cutoffMoments(): iterable
    {
        yield 'last second of 2026, Eastern' => ['2026-12-31 23:59:59', 'America/New_York', RateType::Founding];
        yield 'midnight Jan 1, 2027, Eastern' => ['2027-01-01 00:00:00', 'America/New_York', RateType::Standard];
        yield 'UTC just before Eastern midnight' => ['2027-01-01 04:59:59', 'UTC', RateType::Founding];
        yield 'UTC at Eastern midnight' => ['2027-01-01 05:00:00', 'UTC', RateType::Standard];
        yield 'Pacific on New Year\'s Eve evening' => ['2026-12-31 21:30:00', 'America/Los_Angeles', RateType::Standard];
    }

    #[DataProvider('cutoffMoments')]
    public function test_founding_cutoff_is_midnight_eastern(string $moment, string $timezone, RateType $expected): void
    {
        $this->assertSame($expected, $this->pricing->rateTypeFor(CarbonImmutable::parse($moment, $timezone)));
    }

    public function test_founding_rates_stay_locked_at_renewal(): void
    {
        $quote = $this->pricing->quote(12, $this->standardDate, rateType: RateType::Founding);

        $this->assertSame(RateType::Founding, $quote->rateType);
        $this->assertSame(178800, $quote->total);
    }

    public function test_partner_code_caps_the_rate_at_149(): void
    {
        $code = PartnerCode::factory()->make();

        // Tier rate above $149: the partner rate applies.
        $small = $this->pricing->quote(4, $this->foundingDate, $code);
        $this->assertTrue($small->partnerRateApplied);
        $this->assertSame(15900, $small->tierRate);
        $this->assertSame(14900, $small->rate);
        $this->assertSame(59600, $small->total);

        $standard = $this->pricing->quote(12, $this->standardDate, $code);
        $this->assertTrue($standard->partnerRateApplied);
        $this->assertSame(178800, $standard->total);

        // Tier rate at or below $149: codes do not stack, the agency rate stays.
        foreach ([[12, 178800], [60, 774000]] as [$headcount, $total]) {
            $quote = $this->pricing->quote($headcount, $this->foundingDate, $code);

            $this->assertTrue($quote->partnerCodeValid);
            $this->assertFalse($quote->partnerRateApplied);
            $this->assertSame($total, $quote->total);
        }
    }

    public function test_invalid_partner_codes_are_ignored(): void
    {
        $codes = [
            PartnerCode::factory()->inactive()->make(),
            PartnerCode::factory()->make(['expires_at' => $this->foundingDate->subDay()]),
        ];

        foreach ($codes as $code) {
            $quote = $this->pricing->quote(4, $this->foundingDate, $code);

            $this->assertFalse($quote->partnerCodeValid);
            $this->assertSame(63600, $quote->total);
        }

        // Validity is checked at the payment moment.
        $expiringLater = PartnerCode::factory()->make(['expires_at' => $this->foundingDate->addDay()]);
        $this->assertTrue($this->pricing->quote(4, $this->foundingDate, $expiringLater)->partnerRateApplied);
    }

    public function test_surcharge_applies_to_credit_cards_only(): void
    {
        $credit = $this->pricing->quote(12, $this->foundingDate, cardFunding: 'credit');

        $this->assertSame(178800, $credit->subtotal);
        $this->assertSame(5185, $credit->surcharge); // 2.9% of $1,788 = $51.852
        $this->assertSame(183985, $credit->total);

        $this->assertSame(1844, $this->pricing->quote(4, $this->foundingDate, cardFunding: 'credit')->surcharge); // $18.444

        foreach (['debit', 'prepaid', 'unknown', null] as $funding) {
            $quote = $this->pricing->quote(12, $this->foundingDate, cardFunding: $funding);

            $this->assertSame(0, $quote->surcharge);
            $this->assertSame(178800, $quote->total);
        }
    }

    public function test_surcharge_is_charged_on_the_discounted_amount(): void
    {
        $quote = $this->pricing->quote(4, $this->foundingDate, PartnerCode::factory()->make(), 'credit');

        $this->assertSame(59600, $quote->subtotal);
        $this->assertSame(1728, $quote->surcharge); // 2.9% of $596 = $17.284
    }

    public function test_quote_for_an_agency_uses_its_headcount_locked_rate_and_partner_code(): void
    {
        $agency = Agency::factory()->make(['rate_type' => RateType::Founding]);
        $agency->headcount = 4;
        $agency->setRelation('partnerCode', PartnerCode::factory()->make());

        $quote = $this->pricing->quoteForAgency($agency, $this->standardDate);

        $this->assertSame(RateType::Founding, $quote->rateType);
        $this->assertSame(14900, $quote->rate);
        $this->assertSame(59600, $quote->total);
    }

    public function test_stripe_price_follows_tier_and_rate_type(): void
    {
        config(['agency.tiers.agency_10to49.stripe_prices.standard.price_id' => 'price_123']);

        $this->assertSame('agency_founding_2to9', $this->pricing->quote(4, $this->foundingDate)->stripePrice()['lookup_key']);
        $this->assertSame('agency_founding_50plus', $this->pricing->quote(60, $this->foundingDate)->stripePrice()['lookup_key']);
        $this->assertSame(
            ['lookup_key' => 'agency_standard_10to49', 'price_id' => 'price_123'],
            $this->pricing->quote(12, $this->standardDate)->stripePrice(),
        );
    }

    public function test_summary_matches_the_live_price_box(): void
    {
        $this->assertSame('12 people · Agency Plus · $149 each · $1,788 a year', $this->pricing->quote(12, $this->foundingDate)->summary());
        $this->assertSame('$7,740', Money::format(774000));
        $this->assertSame('$51.85', Money::format(5185));
    }
}
